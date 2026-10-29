<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/analytics_integration.php';
require_once __DIR__.'/manager_tasks_view.php';
try{
    $pdo=getPdo();$user=requireWebUser($pdo);$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $id=(string)($_GET['id']??'');
        if($id!==''){
            $s=$pdo->prepare('SELECT t.*,u.display_name AS assignee_name FROM manager_tasks t JOIN web_users u ON u.id=t.assignee_id WHERE t.id=?');$s->execute([$id]);$task=$s->fetch(PDO::FETCH_ASSOC);
            if(!$task||!taskCanRead($user,$task))sendJson(['status'=>'error','message'=>'Задача недоступна'],404);
            $s=$pdo->prepare('SELECT id,actor_id,event_type,data_json,created_at FROM manager_task_events WHERE task_id=? ORDER BY id DESC LIMIT 100');$s->execute([$id]);
            $client=analyticsDirectoryRecords([(string)$task['client_id']])[(string)$task['client_id']]??null;
            sendJson(['status'=>'success','task'=>taskEvidenceForViewer(array_replace($task,['description'=>taskViewDescription($task)]),$user),'history'=>$s->fetchAll(PDO::FETCH_ASSOC),'client'=>$client?['id'=>$client['id'],'name'=>$client['name']]:null,'role'=>$user['role']]);
        }
        sendJson(taskViewList($pdo,$user,$_GET));
    }
    if($method==='POST'){
        if($user['role']!=='admin')throw new DomainException('Создание доступно администратору');
        $body=readJsonBody();$clientId=taskText($body['client_id']??null,255);
        $client=analyticsDirectoryRecords([$clientId])[$clientId]??null;
        if(!$client||in_array($client['status'],['archived','Архивный'],true))throw new InvalidArgumentException('Клиент ClientsVR не найден или недоступен');
        // Browser cannot supply an automation grant, signal ID, namespace or evidence.
        $created=createManagerTask($pdo,$user,$body);
        sendJson(['status'=>'success','id'=>$created['task']['id'],'existing'=>$created['existing']],$created['existing']?200:201);
    }
    if($method==='PATCH'){
        $body=readJsonBody();$id=taskText($body['id']??null,36);sendJson(updateManagerTask($pdo,$user,$id,$body));
    }
    sendJson(['status'=>'error','message'=>'Метод не разрешён'],405);
}catch(TaskConflict $e){sendJson(['status'=>'error','message'=>$e->getMessage()],409);
}catch(DomainException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('manager_tasks: '.get_class($e));sendJson(['status'=>'error','message'=>'Задачи недоступны. Проверьте миграцию модуля.'],503);}
