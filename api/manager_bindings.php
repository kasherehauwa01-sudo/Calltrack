<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/manager_bindings_service.php';
require_once __DIR__.'/client_directory.php';
require_once __DIR__.'/client_catalog_json_stream.php';
try{
    $pdo=getPdo();$admin=requireWebAdmin($pdo);
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $rows=managerBindingRows($pdo);
        $users=$pdo->query("SELECT id,display_name FROM web_users WHERE role='manager' AND is_active=1 ORDER BY display_name,id")->fetchAll(PDO::FETCH_ASSOC);
        $directory=managerDesignationDirectory(salesBuyerDirectoryRows());
        foreach($directory as &$label){
            $label['suggested_user_ids']=[];
            foreach($users as $user)if(managerNameKey($label['designation'])===managerNameKey($user['display_name']))$label['suggested_user_ids'][]=(int)$user['id'];
        }unset($label);
        sendJson(['status'=>'success','bindings'=>$rows,'designations'=>$directory,'users'=>$users,'counts_source'=>'LOCAL_CACHE_NOT_UPSTREAM_FRESHNESS']);
    }
    if(!in_array($method,['POST','DELETE'],true))sendJson(['status'=>'error','message'=>'Метод не разрешён'],405);
    $body=readJsonBody();
    if(!is_string($body['designation']??null)||!array_key_exists('expected_revision',$body))throw new InvalidArgumentException('Передайте designation и expected_revision');
    $revision=$body['expected_revision'];
    if($revision!==null&&!is_int($revision))throw new InvalidArgumentException('Некорректная revision');
    $id=$method==='DELETE'?null:($body['web_user_id']??null);
    if($method==='POST'&&!is_int($id))throw new InvalidArgumentException('Передайте web_user_id');
    saveManagerBinding($pdo,$admin,$body['designation'],$id,$revision);
    sendJson(['status'=>'success']);
}catch(ManagerBindingConflict $e){sendJson(['status'=>'error','message'=>$e->getMessage()],409);
}catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('manager_bindings: '.get_class($e));sendJson(['status'=>'error','message'=>'Сопоставления недоступны. Проверьте миграцию и локальный кэш ClientsVR.'],503);}
