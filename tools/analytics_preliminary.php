<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../api/config.php';
require_once __DIR__.'/../api/analytics_sync.php';
try{
 $apply=in_array('--apply',$argv,true);$confirm=in_array('--confirm-preliminary-policy',$argv,true);$after='';
 foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--after='))$after=substr($arg,8);elseif(!in_array($arg,['--apply','--confirm-preliminary-policy','--dry-run'],true))throw new DomainException('Invalid option');}
 if($after!==''&&!preg_match('/^[a-f0-9]{64}$/D',$after))throw new DomainException('Invalid cursor');
 if($apply&&!$confirm)throw new DomainException('Explicit preliminary matching policy acknowledgement required');
 if($apply&&in_array('--dry-run',$argv,true))throw new DomainException('Conflicting mode');
 $db=getPdo();$s=$db->prepare('SELECT signal_key,payload_json FROM analytics_inbox WHERE signal_key>? ORDER BY signal_key LIMIT 51');$s->execute([$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>50;$rows=array_slice($rows,0,50);
 $lock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');if(!$lock||!flock($lock,LOCK_SH|LOCK_NB))throw new RuntimeException('Directory refreshing; retry');
 try{
  $events=[];foreach($rows as $row){$event=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);if($event['signal_type']==='CHURN_RISK'&&$event['subject']['kind']==='normalized_name')$events[$row['signal_key']]=$event;}
  $managers=managerBindingRows($db);$revision=clientCatalogRevision();analyticsPrimeReviewNames(array_values(array_unique(array_map(fn($e)=>$e['subject']['key'],$events))),$managers,$revision);$results=[];
  foreach($events as $key=>$event){
   $group=analyticsCurrentReviewGroup($event,$managers,$revision);$match=analyticsPreliminaryMatch($group);
   if($match['status']!=='PRELIMINARY_MATCH'){$results[]=['signal_key'=>$key,'decision'=>$match];continue;}
   $history=salesJournalRequest('GET','/api/integrations/calltrack/analytics/identity-history?client_key='.rawurlencode($event['subject']['key']));
   try{$decision=analyticsPreparePreliminary($db,$event,$group,$history,$apply);}catch(DomainException $e){$decision=['status'=>'BLOCKED','reasons'=>[$e->getMessage()]];}
   $results[]=['signal_key'=>$key,'decision'=>$decision];
  }
  echo json_encode(['policy'=>ANALYTICS_PRELIMINARY_POLICY,'applied'=>$apply,'automation_allowed'=>false,'tasks_created'=>0,'results'=>$results,'next_after'=>$more?$rows[count($rows)-1]['signal_key']:null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $e){fwrite(STDERR,'Preliminary preparation blocked: '.get_class($e).'; no task creation'.PHP_EOL);exit(1);}
