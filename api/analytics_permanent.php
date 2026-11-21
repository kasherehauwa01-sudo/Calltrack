<?php
declare(strict_types=1);
/** Separate opt-in budget. No pilot row is modified or reset. */
function analyticsPermanentPolicy(PDO $pdo, array $event): array {
    if (!$pdo->inTransaction()) throw new DomainException('PERMANENT_TRANSACTION_REQUIRED');
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $p=$pdo->query('SELECT * FROM analytics_permanent_policy WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);
    $e=$event['permanent_policy']??[];
    if (!$p || (int)$p['approved']!==1 || (int)$p['enabled']!==1
        || ($e['version']??null)!=='churn-permanent/1' || ($e['revision']??null)!==(int)$p['revision']
        || ($e['producer_revision']??null)!==(int)$p['producer_revision']
        || ($e['daily_limit']??null)!==(int)$p['daily_limit']
        || ($e['cooldown_days']??null)!==(int)$p['cooldown_days']
        || ($event['generator_version']??null)!==$p['generator_version']
        || (int)$p['daily_limit']<1 || (int)$p['daily_limit']>100
        || (int)$p['cooldown_days']<1 || (int)$p['cooldown_days']>3650)
        throw new DomainException('PERMANENT_POLICY_NOT_APPROVED');
    return $p;
}

/** Atomic check and reservation are inside the existing task/inbox transaction. */
function analyticsPermanentBudget(PDO $pdo, array $event, bool $reserve=false, array $peers=[]): void {
    $p=analyticsPermanentPolicy($pdo,$event); // one policy-row lock serializes all new tasks
    $date=(new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    // Current locking reads are required on MariaDB REPEATABLE READ: the inbox
    // lookup may already have established an older consistent-read snapshot.
    $locking=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $s=$pdo->prepare('SELECT signal_key FROM analytics_permanent_reservations WHERE business_date=? ORDER BY signal_key LIMIT 101'.$locking);
    $s->execute([$date]);
    if (count($s->fetchAll(PDO::FETCH_COLUMN))>=(int)$p['daily_limit']) throw new DomainException('PERMANENT_DAILY_LIMIT');
    if (count($peers)>1000 || array_filter($peers,fn($id)=>!is_string($id))) throw new DomainException('INVALID_REVIEWED_PEERS');
    $ids=array_values(array_unique(array_merge([$event['subject']['key']],$peers)));
    $s=$pdo->prepare("SELECT created_at,evidence_json FROM manager_tasks WHERE client_namespace='clientsvr' AND client_id IN (".implode(',',array_fill(0,count($ids),'?')).") AND family='INACTIVITY' AND source_signal_key IS NOT NULL ORDER BY created_at DESC LIMIT 1".$locking);
    $s->execute($ids);$old=$s->fetch(PDO::FETCH_ASSOC);
    if ($old) {
        $e=json_decode($old['evidence_json'],true,64,JSON_THROW_ON_ERROR);
        // A new episode requires an actual later purchase, never merely closing a task.
        $anchor=$e['signal']['last_purchase']??null;
        $created=strtotime($old['created_at'].' UTC');
        if (!is_string($anchor) || $event['last_purchase']<=$anchor || $created===false
            || time()<$created+86400*(int)$p['cooldown_days']) throw new DomainException('PERMANENT_REPEAT_NOT_ALLOWED');
    }
    if ($reserve) {
        $key=hash('sha256',$event['source']."\0".$event['signal_id']);
        $pdo->prepare('INSERT INTO analytics_permanent_reservations(signal_key,business_date,policy_revision) VALUES(?,?,?)')
            ->execute([$key,$date,(int)$p['revision']]);
    }
}

/** CLI-only operator approval. Draft disables; approval does not enable. */
function saveAnalyticsPermanentPolicy(PDO $pdo,array $input,string $actor):array {
    if (!preg_match('/^[a-zA-Z0-9_.@-]{1,100}$/D',$actor)) throw new DomainException('ACTOR_REQUIRED');
    $pdo->beginTransaction();
    try {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $p=$pdo->query('SELECT * FROM analytics_permanent_policy WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);
        if (!$p || !is_int($input['revision']??null) || $input['revision']!==(int)$p['revision']) throw new DomainException('POLICY_REVISION_CONFLICT');
        $action=$input['action']??null;
        if ($action==='draft') {
            foreach (['daily_limit'=>[1,100],'cooldown_days'=>[1,3650],'producer_revision'=>[1,PHP_INT_MAX]] as $key=>$range)
                if (!is_int($input[$key]??null)||$input[$key]<$range[0]||$input[$key]>$range[1]) throw new DomainException('INVALID_POLICY');
            if (($input['generator_version']??null)!=='inactivity/aviator-churn-pilot/2') throw new DomainException('ALGORITHM_NOT_REVIEWED');
            $pdo->prepare('UPDATE analytics_permanent_policy SET daily_limit=?,cooldown_days=?,producer_revision=?,generator_version=?,approved=0,enabled=0,revision=revision+1 WHERE id=1')
                ->execute([$input['daily_limit'],$input['cooldown_days'],$input['producer_revision'],$input['generator_version']]);
        } elseif ($action==='approve') {
            if (($input['confirm_business_approval']??null)!==true || (int)$p['daily_limit']<1 || (int)$p['cooldown_days']<1 || $p['generator_version']!=='inactivity/aviator-churn-pilot/2') throw new DomainException('EXPLICIT_APPROVAL_REQUIRED');
            $pdo->exec('UPDATE analytics_permanent_policy SET approved=1,enabled=0,revision=revision+1 WHERE id=1');
        } elseif (in_array($action,['enable','disable'],true)) {
            if ($action==='enable' && (int)$p['approved']!==1) throw new DomainException('POLICY_NOT_APPROVED');
            $pdo->prepare('UPDATE analytics_permanent_policy SET enabled=? WHERE id=1')->execute([$action==='enable'?1:0]);
        } else throw new DomainException('INVALID_POLICY_ACTION');
        $p=$pdo->query('SELECT * FROM analytics_permanent_policy WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('INSERT INTO analytics_permanent_policy_events(actor,revision,action,policy_json) VALUES(?,?,?,?)')
            ->execute([$actor,(int)$p['revision'],$action,json_encode($p,JSON_THROW_ON_ERROR)]);
        $pdo->commit();return $p;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
}

/** Validate the entire bounded page before making even diagnostic writes. */
function analyticsPermanentValidatePage(array $input): array {
    if(array_diff(array_keys($input),['job_id','release_id','events']))throw new DomainException('INVALID_PAGE');
    foreach(['job_id','release_id'] as $key)if(!is_string($input[$key]??null)||!preg_match('/^[a-zA-Z0-9-]{1,36}$/D',$input[$key]))throw new DomainException('INVALID_CONTEXT');
    if(!is_array($input['events']??null)||!array_is_list($input['events'])||!$input['events']||count($input['events'])>25)throw new DomainException('INVALID_PAGE');
    $seen=[];$events=[];
    foreach($input['events'] as $item){
        if(!is_array($item)||array_diff(array_keys($item),['event_id','event_hash','event_json'])
           ||!is_int($item['event_id']??null)||$item['event_id']<1||isset($seen[$item['event_id']]))throw new DomainException('INVALID_EVENT_ID');
        $seen[$item['event_id']]=true;
        $raw=$item['event_json']??null;
        if(!is_string($raw)||strlen($raw)>65536||!is_string($item['event_hash']??null)
           ||!preg_match('/^[a-f0-9]{64}$/D',$item['event_hash'])||!hash_equals(hash('sha256',$raw),$item['event_hash']))throw new DomainException('INVALID_EVENT_BYTES');
        // Hash exact producer bytes: Python and PHP render floating point differently.
        $event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($event)||($event['run']['id']??null)!==$input['release_id']||!is_array($event['permanent_policy']??null))throw new DomainException('EVENT_CONTEXT_MISMATCH');
        analyticsValidateEvent($event);
        $events[]=[$item,$event];
    }
    return $events;
}

/** Existing inbox/task transaction owns every gate and budget reservation. */
function analyticsPermanentPage(PDO $pdo,array $input,string $command,int $now):array {
    if(!in_array($command,['preflight','deliver'],true))throw new DomainException('INVALID_COMMAND');
    $validated=analyticsPermanentValidatePage($input);
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $lock='analytics-permanent-consumer';$locked=false;$refreshLock=null;
    try{
        if($mysql){
            $s=$pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);
            if((int)$s->fetchColumn()!==1)throw new DomainException('CONSUMER_BUSY');
            $locked=true;
        }
        $refreshLock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');
        if(!$refreshLock||!flock($refreshLock,LOCK_SH|LOCK_NB))throw new DomainException('CLIENTS_REFRESH_IN_PROGRESS');
        $ids=[];
        foreach($validated as [$item,$event]){
            $binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));
            if($binding&&!empty($binding['client_id']))$ids[(string)$binding['client_id']]=(string)$binding['client_id'];
        }
        $clients=analyticsDirectoryRecords(array_values($ids));
        $policy=analyticsIntegrationPolicy($pdo);$policy['cache_revision']=clientCatalogRevision();
        analyticsPrimeReviewGroups(array_column($validated,1),managerBindingRows($pdo),$policy['cache_revision']);
        $results=[];
        foreach($validated as [$item,$event]){
            $binding=analyticsBinding($pdo,analyticsSubjectKey($event['subject'],$event['run']['scope']));
            try{
                $result=processAnalyticsEvent($pdo,$event,$clients[(string)($binding['client_id']??'')]??null,$policy,$command==='deliver'?'enabled':'dry_run',$now);
            }catch(TaskConflict $e){
                rejectAnalyticsDelivery($pdo,$event,$input['release_id'],'EVENT_CONFLICT');
                $result=['decision'=>'BLOCKED','reasons'=>['EVENT_CONFLICT'],'task_id'=>null];
            }
            if($command==='deliver'&&!in_array($result['decision'],['TASK_CREATED','TASK_EXISTS','BLOCKED','REVIEW'],true)){
                // A stale revision must not acknowledge an unrelated newer receipt.
                $result=['decision'=>'BLOCKED','reasons'=>['STALE_SIGNAL_REVISION'],'task_id'=>null];
            }
            $result['created_for_release']=false;
            if(!empty($result['task_id'])){
                $s=$pdo->prepare('SELECT source_signal_key,evidence_json FROM manager_tasks WHERE id=?');$s->execute([$result['task_id']]);$stored=$s->fetch(PDO::FETCH_ASSOC);
                $proof=$stored?json_decode($stored['evidence_json'],true,64,JSON_THROW_ON_ERROR):[];
                $result['created_for_release']=$stored&&$stored['source_signal_key']===analyticsSignalKey($event)&&($proof['signal']['run']['id']??null)===$input['release_id'];
            }
            if(count($result['reasons'])>30)throw new DomainException('RECEIPT_REASON_LIMIT');
            foreach($result['reasons'] as $reason)if(!is_string($reason)||!preg_match('/^[A-Z0-9_]{1,64}$/D',$reason))throw new DomainException('INVALID_RECEIPT_REASON');
            $results[]=array_merge(['event_id'=>$item['event_id'],'event_hash'=>$item['event_hash']],$result);
        }
        return ['job_id'=>$input['job_id'],'release_id'=>$input['release_id'],'receipts'=>$results];
    }finally{
        if(is_resource($refreshLock)){flock($refreshLock,LOCK_UN);fclose($refreshLock);}
        if($locked)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}
