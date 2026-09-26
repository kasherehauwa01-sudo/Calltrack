<?php
declare(strict_types=1);
require_once __DIR__.'/sales_journal.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sendJson(['status'=>'error','message'=>'Метод не поддерживается'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$data=readJsonBody();[$from,$to]=salesJournalPeriod($data);$manager=trim((string)($data['manager']??''));
    $clients=salesJournalCommunicationClients($pdo,$user,$manager,$from,$to);
    if(!$clients)sendJson(['status'=>'success','available'=>true,'items'=>[],'clients'=>0,'requests'=>0]);
    $result=loadSalesJournalBatch($clients,$from,$to);allowSalesJournalDetails($result['items']);
    error_log('Sales Journal batch: period='.$from.'..'.$to.' clients='.count($clients).' sales='.count($result['items']).' requests='.$result['requests']);
    sendJson(['status'=>'success','available'=>true,'items'=>$result['items'],'clients'=>count($clients),'requests'=>$result['requests']]);
}catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}catch(Throwable $e){error_log('Sales Journal batch unavailable: '.$e->getMessage());sendJson(['status'=>'success','available'=>false,'error'=>'sales_journal_unavailable','items'=>[]]);}
