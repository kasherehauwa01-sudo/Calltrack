<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sendJson(['status'=>'error','message'=>'Разрешён только GET'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$records=clientCatalogRecords();$managers=clientCatalogManagers($pdo);
    $activity=clientCatalogActivity($pdo,$records,$managers,$user);
    sendJson(['status'=>'success']+clientCatalogPage($records,$managers,$activity,$user,$_GET));
}catch(ClientCatalogAccessDenied $e){sendJson(['status'=>'error','message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Clients list: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить клиентов. Повторите попытку.'],503);}
