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
        $object = json_decode($param, 1);
        if ($object['object_kind'] != 'merge_request' && $object['object_attributes']['merge_status'] != 'can_be_merged') {
            return;
        }
        $commitId = $object['object_attributes']['merge_commit_sha'];
        $user = User::find()->where(['username' => $object['object_attributes']['last_commit']['author']['email']])->one();
        $project = Project::find()->where(['name' => $object['project']['name']])->one();

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
        Yii::$app->redis->lpush(Yii::$app->params['user.publish_queue'], $task->id);
    }
}

