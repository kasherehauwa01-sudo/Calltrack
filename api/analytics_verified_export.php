<?php
declare(strict_types=1);
/** Read-only service endpoint; the token cannot confirm, revoke, or create tasks. */
require_once __DIR__.'/config.php';
require_once __DIR__.'/analytics_sync.php';
require_once __DIR__.'/analytics_preliminary.php';
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET')sendJson(['status'=>'error'],405);
 $token=getenv('CALLTRACK_BINDINGS_EXPORT_TOKEN')?:'';$header=$_SERVER['HTTP_AUTHORIZATION']??'';
 if(strlen($token)<32)sendJson(['status'=>'error','message'=>'Verified review export disabled'],503);
 if(!hash_equals('Bearer '.$token,$header))sendJson(['status'=>'error'],401);
 $after=$_GET['after']??'';if(!is_string($after)||($after!==''&&!preg_match('/^[a-f0-9]{64}$/D',$after)))throw new DomainException('Invalid cursor');
 $db=getPdo();$s=$db->prepare('SELECT * FROM analytics_client_bindings WHERE subject_key>? ORDER BY subject_key LIMIT 6');$s->execute([$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>5;$rows=array_slice($rows,0,5);$receipts=[];$blocked=[];
 $lock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');
 if(!$lock||!flock($lock,LOCK_SH|LOCK_NB))throw new RuntimeException('Directory refresh; retry');
 try{
  $managers=managerBindingRows($db);$revision=clientCatalogRevision();
  $names=[];foreach($rows as $row){$review=json_decode($row['evidence'],true);if(is_array($review)&&($review['origin_subject_key']??null)===$row['subject_key']&&analyticsBindingAssignable($row))$names[]=$review['client_key'];}
  analyticsPrimeReviewNames(array_values(array_unique($names)),$managers,$revision);
  foreach($rows as $binding){
   $e=json_decode($binding['evidence'],true);if(!is_array($e)||($e['origin_subject_key']??null)!==$binding['subject_key'])continue;
   if(!analyticsBindingAssignable($binding)){$receipts[]=analyticsExportReviewRefusal($binding,time());continue;}
   $stored=json_decode($binding['subject_json'],true,32,JSON_THROW_ON_ERROR);$event=['subject'=>$stored['subject'],'run'=>['scope'=>$stored['scope']]];
   $group=analyticsCurrentReviewGroup($event,$managers,$revision);
   $history=salesJournalRequest('GET','/api/integrations/calltrack/analytics/identity-history?client_key='.rawurlencode($e['client_key']));analyticsReviewHistory($event,$history);
   try{$receipts[]=analyticsExportReview($binding,$group,$history,time());}catch(DomainException $ex){$blocked[]=['subject_hash'=>$binding['subject_key'],'reason'=>'REVIEW_REVOKED_OR_SOURCE_GROUP_CHANGED'];}
  }
  sendJson(['version'=>1,'receipts'=>$receipts,'blocked'=>$blocked,'next_after'=>$more?$rows[count($rows)-1]['subject_key']:null,'automation_allowed'=>false]);
 }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}catch(Throwable $e){error_log('verified_export: '.get_class($e));sendJson(['status'=>'error','message'=>'Verified export unavailable; no assignments authorized'],503);}
