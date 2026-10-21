<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/analytics_task_policy.php';
try{
    $pdo=getPdo();$admin=requireWebAdmin($pdo);
    if($_SERVER['REQUEST_METHOD']==='GET')sendJson(['status'=>'success','policy'=>analyticsTaskPolicy($pdo)]);
    if($_SERVER['REQUEST_METHOD']!=='POST')sendJson(['status'=>'error','message'=>'Метод не разрешён'],405);
    saveAnalyticsTaskPolicy($pdo,$admin,readJsonBody());sendJson(['status'=>'success','policy'=>analyticsTaskPolicy($pdo)]);
}catch(TaskConflict $e){sendJson(['status'=>'error','message'=>$e->getMessage()],409);
}catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('analytics_policy: '.get_class($e));sendJson(['status'=>'error','message'=>'Политика недоступна. Проверьте миграцию.'],503);}
