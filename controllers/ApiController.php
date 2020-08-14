<?php

namespace app\controllers;

use Yii;
use app\components\Controller;
use app\models\Task;
use app\models\Project;
use app\models\User;
use app\logic\WalleLogic;

class ApiController extends Controller
{

    public $enableCsrfValidation = false;
    public function actionIndex()
    {
        $param = file_get_contents('php://input');
        //@todo remove later,just for debug
        error_log("\n" . $param, 3, '/tmp/webhook.log');
        if ($_SERVER['HTTP_X_GITLAB_TOKEN'] != Yii::$app->params['gitlab_secrect_token']) {
            Yii::error("Token:{$_SERVER['HTTP_X_GITLAB_TOKEN']} Invalid");
            return;
        }
        $object = json_decode($param, 1);
        if ($object['object_attributes']['target_branch'] != 'master' && $object['object_kind'] != 'merge_request' && $object['object_attributes']['state'] != 'merged') {
            return;
        }
        $commitId = $object['object_attributes']['merge_commit_sha'];
        if (empty($commitId)) {
            return;
        }
        $user = User::find()->where(['username' => $object['object_attributes']['last_commit']['author']['email']])->one();
        if (empty($user)) {
            Yii::error("user:{$object['object_attributes']['last_commit']['author']['email']} not found");
            return;
        }
        $project = Project::find()->where(['name' => $object['project']['name'], 'level' => '3'])->one();
        if (empty($project)) {
            Yii::error("project:{$object['project']['name']} not found");
            return;
        }
        $task = new Task();
        $task->title = $object['object_attributes']['last_commit']['message'];
        $task->user_id = $user->id;
        $task->project_id = $project->id;
        $task->commit_id = substr($commitId, 0, 7);
        $task->branch = 'master';
        $task->status = Task::STATUS_PASS;
        if (!$task->save()) {
            var_dump($task->errors);
        }
        //发布时间太久了，采用队列移步发布的方式处理
        Yii::$app->redis->lpush(Yii::$app->params['publish_queue'], json_encode([
            'task_id'   => $task->id,
            'task_name' => $task->title,
            'commit_id' => $task->commit_id,
            'uid'       => $user->id,
            'email'     => $user->email]));
    }
}

