<?php
declare(strict_types=1);
require_once __DIR__.'/sales_buyer_catalog.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sendJson(['status'=>'error','message'=>'Разрешён только GET'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);
    // Read-only list queries must not queue behind other requests holding this session.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $result=salesBuyerPage(clientCatalogManagers($pdo),$user,$_GET);
    foreach($result['items'] as &$item)unset($item['unique_phones'],$item['owner_id']);unset($item);
    sendJson(['status'=>'success']+$result);
}catch(SalesBuyerUpgradeRequired $e){sendJson(['status'=>'error','message'=>$e->getMessage()],503);}
catch(ClientCatalogAccessDenied $e){sendJson(['status'=>'error','message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Clients list: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить клиентов. Повторите попытку.'],503);}
