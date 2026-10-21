<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_integration.php';
require_once __DIR__.'/sales_journal.php';
require_once __DIR__.'/analytics_task_policy.php';
function analyticsIntegrationMode(): string {
    $mode=getenv('CALLTRACK_ANALYTICS_MODE')?:'disabled';
    if(!in_array($mode,['disabled','dry_run','enabled'],true))throw new RuntimeException('Invalid CALLTRACK_ANALYTICS_MODE');return $mode;
}
function analyticsIntegrationPolicy(?PDO $pdo=null): array {
    $file=getenv('CALLTRACK_ANALYTICS_POLICY_FILE')?:'';
    if($file==='')return $pdo?analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo)):[];
    if(!is_file($file))throw new RuntimeException('Analytics policy file missing');
    $raw=file_get_contents($file);if($raw===false||strlen($raw)>65536)throw new RuntimeException('Invalid policy file');
    $policy=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($policy))throw new RuntimeException('Invalid policy');
    $policy['cache_revision']=clientCatalogRevision();
    // DB business policy always overrides file values; configuration cannot bypass approval.
    return array_replace($policy,$pdo?analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo)):['policy_approved'=>false]);
}
function rejectAnalyticsDelivery(PDO $pdo,array $event,string $runId,string $reason): void {
    $hash=hash('sha256',json_encode(analyticsCanonical($event),JSON_THROW_ON_ERROR));
    $key=hash('sha256',$runId."\0".$hash."\0".$reason);
    $s=$pdo->prepare('SELECT event_key FROM analytics_delivery_rejections WHERE event_key=?');$s->execute([$key]);if($s->fetchColumn())return;
    $id=is_string($event['signal_id']??null)&&strlen($event['signal_id'])<=64?$event['signal_id']:null;
    $pdo->prepare('INSERT INTO analytics_delivery_rejections(event_key,run_id,signal_id,reason_code,event_hash) VALUES(?,?,?,?,?)')->execute([$key,$runId,$id,$reason,$hash]);
}
/** Caller holds single-consumer DB/CLI lock. Every retry reuses immutable run ID. */
function syncAnalytics(PDO $pdo,string $mode,array $policy,?callable $transport=null,int $maxPages=5): array {
    if($mode==='disabled')return ['mode'=>$mode,'pages'=>0,'processed'=>0];
    if(!in_array($mode,['dry_run','enabled'],true))throw new InvalidArgumentException('Invalid mode');
    $result=['mode'=>$mode,'pages'=>0,'processed'=>0,'rejected'=>0];
    for($page=0;$page<min(20,max(1,$maxPages));$page++){
        $state=$pdo->query('SELECT * FROM analytics_sync_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);if(!$state)throw new RuntimeException('Sync migration missing');
        if(!$state['current_run_id']){
            $response=salesJournalRequest('GET','/api/integrations/calltrack/analytics/publications?after='.(int)$state['publication_sequence'].'&page_size=1',null,$transport);
            $publication=$response['items'][0]??null;if(!$publication)break;
            if(!is_int($publication['sequence']??null)||$publication['sequence']<=(int)$state['publication_sequence']||!preg_match('/^[a-zA-Z0-9-]{1,36}$/D',(string)($publication['run_id']??'')))throw new RuntimeException('Invalid publication');
            $pdo->prepare('UPDATE analytics_sync_state SET current_run_id=?,current_sequence=?,after_change=0 WHERE id=1')->execute([$publication['run_id'],$publication['sequence']]);
            $state['current_run_id']=$publication['run_id'];$state['current_sequence']=$publication['sequence'];$state['after_change']=0;
        }
        $response=salesJournalRequest('GET','/api/integrations/calltrack/analytics/runs/'.rawurlencode($state['current_run_id']).'/changes?after='.(int)$state['after_change'].'&page_size=50',null,$transport);
        if(($response['run']['id']??null)!==$state['current_run_id']||($response['run']['status']??null)!=='SUCCEEDED'||!is_array($response['items']??null)||count($response['items'])>50)throw new RuntimeException('Invalid frozen run page');
        $events=$response['items'];$ids=[];$valid=[];
        foreach($events as $event){
            if(!is_array($event))throw new RuntimeException('Invalid event page');
            try{analyticsValidateEvent($event);}catch(InvalidArgumentException $e){rejectAnalyticsDelivery($pdo,$event,$state['current_run_id'],'INVALID_EVENT');$result['rejected']++;continue;}
            $valid[]=$event;
            if(($event['run']['id']??null)!==$state['current_run_id'])throw new RuntimeException('Mixed run page');
            $binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));if($binding&&$binding['status']==='VERIFIED'&&!empty($binding['client_id']))$ids[(string)$binding['client_id']]=(string)$binding['client_id'];
        }
        // Coordinate with the existing refresh writer, not a new independent lock.
        $refreshLock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');
        if($refreshLock===false||!flock($refreshLock,LOCK_SH|LOCK_NB)){if(is_resource($refreshLock))fclose($refreshLock);throw new RuntimeException('Clients refresh in progress; retry');}
        try{
            $clients=analyticsDirectoryRecords(array_values($ids));
            $currentPolicy=$policy;$currentPolicy['cache_revision']=clientCatalogRevision();
            foreach($valid as $event){$binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));try{processAnalyticsEvent($pdo,$event,$clients[(string)($binding['client_id']??'')]??null,$currentPolicy,$mode,time());$result['processed']++;}catch(TaskConflict $e){rejectAnalyticsDelivery($pdo,$event,$state['current_run_id'],'EVENT_CONFLICT');$result['rejected']++;}}
        }finally{flock($refreshLock,LOCK_UN);fclose($refreshLock);}
        $next=$response['next_after']??null;
        if($next!==null){
            if(!is_int($next)||$next<=(int)$state['after_change']||!$events)throw new RuntimeException('Invalid progress cursor');
            $pdo->prepare('UPDATE analytics_sync_state SET after_change=? WHERE id=1')->execute([$next]);
        }else{$pdo->prepare('UPDATE analytics_sync_state SET publication_sequence=current_sequence,current_run_id=NULL,current_sequence=NULL,after_change=0 WHERE id=1')->execute();}
        $result['pages']++;
    }
    return $result;
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{
        $mode=analyticsIntegrationMode();
        if($mode==='disabled'){echo json_encode(['mode'=>'disabled','network_requests'=>0]).PHP_EOL;exit(0);}
        $pdo=getPdo();
        // MySQL connection-scoped lock, never held by a web request. No remote writes.
        $lock='analytics-sync-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
        $s=$pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Analytics sync already running');
        try{echo json_encode(syncAnalytics($pdo,$mode,analyticsIntegrationPolicy($pdo)),JSON_THROW_ON_ERROR).PHP_EOL;}
        finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }catch(Throwable $e){fwrite(STDERR,'Analytics sync failed: '.get_class($e).'; retry next invocation, cursor retained'.PHP_EOL);exit(1);}
}elseif(PHP_SAPI!=='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
