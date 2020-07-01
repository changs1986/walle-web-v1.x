<?php
<?php
/* *****************************************************************
 * @Author: felix
 * @Created Time : 一  7/01 10:20:30 2020
 *
 * @File Name: WalleController.php
 * @Description:
 * *****************************************************************/

namespace app\logic;

use app\components\Command;
use app\components\Folder;
use app\components\Repo;
use app\components\Task as WalleTask;
use app\models\Project;
use app\models\Record;
use app\models\Task as TaskModel;
use yii;

class WalleLogic
{

    /**
     * 项目配置
     */
    protected $conf;

    /**
     * 上线任务配置
     */
    protected $task;

    /**
     * Walle的高级任务
     */
    protected $walleTask;

    /**
     * Ansible 任务
     */
    protected $ansible;

    /**
     * Walle的文件目录操作
     */
    protected $walleFolder;

    public $enableCsrfValidation = false;

    /**
     * 发起上线
     *
     * @throws \Exception
     */
    public function startDeploy($taskId)
    {
        if (!$taskId) {
            throw new InvalidArgumentException("task id is required");
        }
        $this->task = TaskModel::findOne($taskId);
        if (!$this->task) {
            throw new \Exception(yii::t('walle', 'deployment id not exists'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }
        // 任务失败或者审核通过时可发起上线
        if (!in_array($this->task->status, [TaskModel::STATUS_PASS, TaskModel::STATUS_FAILED])) {
            throw new \Exception(yii::t('walle', 'deployment only done for once'));
        }
        // 清除历史记录
        Record::deleteAll(['task_id' => $this->task->id]);

        // 项目配置
        $this->conf = Project::getConf($this->task->project_id);
        $this->walleTask = new WalleTask($this->conf);
        $this->walleFolder = new Folder($this->conf);
        try {
            if ($this->task->action == TaskModel::ACTION_ONLINE) {
                $this->_makeVersion();
                $this->_initWorkspace();
                $this->_preDeploy();
                $this->_revisionUpdate();
                $this->_postDeploy();
                $this->_transmission();
                $this->_updateRemoteServers($this->task->link_id, $this->conf->post_release_delay);
                $this->_cleanRemoteReleaseVersion();
                $this->_cleanUpLocal($this->task->link_id);
            } else {
                $this->_rollback($this->task->ex_link_id);
            }

            /** 至此已经发布版本到线上了，需要做一些记录工作 */

            // 记录此次上线的版本（软链号）和上线之前的版本
            ///对于回滚的任务不记录线上版本
            if ($this->task->action == TaskModel::ACTION_ONLINE) {
                $this->task->ex_link_id = $this->conf->version;
            }
            // 第一次上线的任务不能回滚、回滚的任务不能再回滚
            if ($this->task->action == TaskModel::ACTION_ROLLBACK || $this->task->id == 1) {
                $this->task->enable_rollback = TaskModel::ROLLBACK_FALSE;
            }
            $this->task->status = TaskModel::STATUS_DONE;
            $this->task->save();

            // 可回滚的版本设置
            $this->_enableRollBack();

            // 记录当前线上版本（软链）回滚则是回滚的版本，上线为新版本
            $this->conf->version = $this->task->link_id;
            $this->conf->save();
        } catch (\Exception $e) {
            $this->task->status = TaskModel::STATUS_FAILED;
            $this->task->save();
            // 清理本地部署空间
            $this->_cleanUpLocal($this->task->link_id);

            throw $e;
        }
    }

    /**
     * 产生一个上线版本
     */
    private function _makeVersion()
    {
        $version = date("Ymd-His", time());
        $this->task->link_id = $version;

        return $this->task->save();
    }

    /**
     * 检查目录和权限，工作空间的准备
     * 每一个版本都单独开辟一个工作空间，防止代码污染
     *
     * @return bool
     * @throws \Exception
     */
    private function _initWorkspace()
    {
        $sTime = Command::getMs();
        // 本地宿主机工作区初始化
        $this->walleFolder->initLocalWorkspace($this->task);

        // 远程目标目录检查，并且生成版本目录
        $ret = $this->walleFolder->initRemoteVersion($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleFolder, $this->task->id, Record::_PERMSSION, $duration);

        if (!$ret) {
            throw new \Exception(yii::t('walle', 'init deployment workspace error'));
        }

        return true;
    }

    /**
     * 更新代码文件
     *
     * @return bool
     * @throws \Exception
     */
    private function _revisionUpdate()
    {
        // 更新代码文件
        $revision = Repo::getRevision($this->conf);
        $sTime = Command::getMs();
        $ret = $revision->updateToVersion($this->task); // 更新到指定版本
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($revision, $this->task->id, Record::_CLONE, $duration);

        if (!$ret) {
            throw new \Exception(yii::t('walle', 'update code error'));
        }

        return true;
    }

    /**
     * 部署前置触发任务
     * 在部署代码之前的准备工作，如git的一些前置检查、vendor的安装（更新）
     *
     * @return bool
     * @throws \Exception
     */
    private function _preDeploy()
    {
        $sTime = Command::getMs();
        $ret = $this->walleTask->preDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::_PRE_DEPLOY, $duration);

        if (!$ret) {
            throw new \Exception(yii::t('walle', 'pre deploy task error'));
        }

        return true;
    }


    /**
     * 部署后置触发任务
     * git代码检出之后，可能做一些调整处理，如vendor拷贝，配置环境适配（mv config-test.php config.php）
     *
     * @return bool
     * @throws \Exception
     */
    private function _postDeploy()
    {
        $sTime = Command::getMs();
        $ret = $this->walleTask->postDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::_POST_DEPLOY, $duration);

        if (!$ret) {
            throw new \Exception(yii::t('walle', 'post deploy task error'));
        }

        return true;
    }

    /**
     * 传输文件/目录到指定目标机器
     *
     * @return bool
     * @throws \Exception
     */
    private function _transmission()
    {

        $sTime = Command::getMs();

        if (Project::getAnsibleStatus()) {
            // ansible copy
            $this->walleFolder->ansibleCopyFiles($this->conf, $this->task);
        } else {
            // 循环 scp
            $this->walleFolder->scpCopyFiles($this->conf, $this->task);
        }

        // 记录执行时间
        $duration = Command::getMs() - $sTime;

        Record::saveRecord($this->walleFolder, $this->task->id, Record::_SYNC, $duration);

        return true;
    }

    /**
     * 执行远程服务器任务集合
     * 对于目标机器更多的时候是一台机器完成一组命令，而不是每条命令逐台机器执行
     *
     * @param string  $version
     * @param integer $delay 每台机器延迟执行post_release任务间隔, 不推荐使用, 仅当业务无法平滑重启时使用
     * @throws \Exception
     */
    private function _updateRemoteServers($version, $delay = 0)
    {
        $cmd = [];
        // pre-release task
        if (($preRelease = WalleTask::getRemoteTaskCommand($this->conf->pre_release, $version))) {
            $cmd[] = $preRelease;
        }
        // link
        if (($linkCmd = $this->walleFolder->getLinkCommand($version))) {
            $cmd[] = $linkCmd;
        }
        // post-release task
        if (($postRelease = WalleTask::getRemoteTaskCommand($this->conf->post_release, $version))) {
            $cmd[] = $postRelease;
        }

        $sTime = Command::getMs();
        // run the task package
        $ret = $this->walleTask->runRemoteTaskCommandPackage($cmd, $delay);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::_UPDATE_REMOTE, $duration);
        if (!$ret) {
            throw new \Exception(yii::t('walle', 'update servers error'));
        }

        return true;
    }

    /**
     * 可回滚的版本设置
     *
     * @return int
     */
    private function _enableRollBack()
    {
        $where = ' status = :status AND project_id = :project_id ';
        $param = [':status' => TaskModel::STATUS_DONE, ':project_id' => $this->task->project_id];
        $offset = TaskModel::find()
                           ->select(['id'])
                           ->where($where, $param)
                           ->orderBy(['id' => SORT_DESC])
                           ->offset($this->conf->keep_version_num)
                           ->limit(1)
                           ->scalar();
        if (!$offset) {
            return true;
        }

        $where .= ' AND id <= :offset ';
        $param[':offset'] = $offset;

        return TaskModel::updateAll(['enable_rollback' => TaskModel::ROLLBACK_FALSE], $where, $param);
    }

    /**
     * 只保留最大版本数，其余删除过老版本
     */
    private function _cleanRemoteReleaseVersion()
    {
        return $this->walleTask->cleanUpReleasesVersion();
    }

    /**
     * 执行远程服务器任务集合回滚，只操作pre-release、link、post-release任务
     *
     * @param $version
     * @throws \Exception
     */
    public function _rollback($version)
    {
        return $this->_updateRemoteServers($version);
    }

    /**
     * 收尾工作，清除宿主机的临时部署空间
     */
    private function _cleanUpLocal($version = null)
    {
        // 创建链接指向
        $this->walleFolder->cleanUpLocal($version);

        return true;
    }
}
