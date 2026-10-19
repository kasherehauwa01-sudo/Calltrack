<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog_purchases.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sendJson(['status'=>'error','message'=>'Разрешён только GET'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);
    // Read-only list queries must not queue behind other requests holding this session.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $records=clientCatalogRecords();$managers=clientCatalogManagers($pdo);
    $emptyPage=clientCatalogPage($records,$managers,[],$user,$_GET); // Validate filters before starting any work.
    $purchases=clientCatalogPurchasesState(($_GET['retry_purchases']??'')==='1');
    if(!$purchases['ready'])$emptyPage['items']=[];
    if(!$purchases['ready'])sendJson(['status'=>'success','purchase_state'=>'warming','processed'=>$purchases['processed'],'purchase_total'=>$purchases['total']]+$emptyPage,202);
    $scope=webManagerScope($user);
    $key='activity-v2|'.clientCatalogRevision().'|'.hash('sha256',json_encode([$scope,$managers],JSON_THROW_ON_ERROR));
    $activity=clientCatalogCachedArray($key,static fn()=>clientCatalogActivity($pdo,$records,$managers,$user));
    sendJson(['status'=>'success']+clientCatalogPage($records,$managers,$activity,$user,$_GET,null,$purchases['dates'])+['purchase_state'=>$purchases['state'],'purchases_updated_at'=>$purchases['updated_at']]);
}catch(ClientCatalogAccessDenied $e){sendJson(['status'=>'error','message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Clients list: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить клиентов. Повторите попытку.'],503);}
