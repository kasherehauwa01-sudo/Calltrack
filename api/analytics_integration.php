<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_source_trust.php';
require_once __DIR__.'/manager_bindings_service.php';
require_once __DIR__.'/manager_tasks_service.php';
require_once __DIR__.'/analytics_task_policy.php';
require_once __DIR__.'/client_directory.php';
require_once __DIR__.'/client_catalog_cache.php';
require_once __DIR__.'/client_catalog_json_stream.php';

require_once __DIR__.'/analytics_preliminary.php';

function analyticsCanonical(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value,SORT_STRING);
    foreach($value as &$item)$item=analyticsCanonical($item);unset($item);return $value;
}
function analyticsSubjectKey(array $subject,array $scope): string {
    return hash('sha256',json_encode([$subject['kind'],$subject['version'],$subject['key'],analyticsCanonical($scope)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function analyticsSignalKey(array $event): string { return hash('sha256',$event['source']."\0".$event['signal_id']); }
function analyticsValidateEvent(array $event): void {
    foreach(['source','signal_id','status','signal_type','as_of','last_purchase','mode','calculated_at'] as $key)if(!is_string($event[$key]??null)||strlen($event[$key])>128)throw new InvalidArgumentException('Invalid signal envelope');
    if($event['source']!=='sales-journal'||!preg_match('/^[a-zA-Z0-9-]{1,64}$/D',$event['signal_id'])||!is_int($event['revision']??null)||$event['revision']<1)throw new InvalidArgumentException('Invalid signal identity');
    if(!in_array($event['status'],['ACTIVE','SUSPENDED','RESOLVED'],true)||!in_array($event['signal_type'],['PURCHASE_OVERDUE','CHURN_RISK'],true)||($event['family']??null)!=='INACTIVITY')throw new InvalidArgumentException('Unsupported signal');
    $s=$event['subject']??null;
    if(!is_array($s)||!in_array($s['kind']??null,['normalized_name','external_id'],true)||!is_int($s['version']??null)||$s['version']<1||!is_string($s['key']??null)||$s['key']===''||strlen($s['key'])>2048)throw new InvalidArgumentException('Invalid subject');
    foreach(['as_of','last_purchase'] as $key){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$event[$key]);if(!$d||$d->format('Y-m-d')!==$event[$key])throw new InvalidArgumentException('Invalid date');}
    foreach(['score','confidence'] as $key){$v=$event[$key]??null;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>($key==='score'?100:1))throw new InvalidArgumentException('Invalid score');}
    if(!is_array($event['run']??null)||!is_array($event['run']['scope']??null)||!is_array($event['metrics']??null)||!is_bool($event['automation_allowed']??null))throw new InvalidArgumentException('Missing evidence');
    if(strlen(json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>65536)throw new InvalidArgumentException('Signal too large');
}
/** Indexed SQLite batch, otherwise one bounded-memory stream. Duplicate IDs are quarantined. */
function analyticsDirectoryRecords(array $ids): array {
    $wanted=array_fill_keys($ids,true);$found=[];$duplicates=[];
    if(!$wanted)return [];
    if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())){
        $pdo=new PDO('sqlite:'.clientsSqliteFile(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only=ON');
        $s=$pdo->prepare('SELECT client_id,payload_json FROM clients WHERE client_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$s->execute(array_values($ids));
        $rows=(static function()use($s){while($row=$s->fetch(PDO::FETCH_ASSOC)){ $payload=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);if((string)($payload['id']??'')!==(string)$row['client_id'])throw new RuntimeException('Directory ID mismatch');yield $payload; }})();
    }else{$rows=salesBuyerDirectoryRows();}
    foreach($rows as $row){$id=(string)($row['id']??$row['fields']['id']??'');if(!isset($wanted[$id]))continue;
        if(isset($found[$id]))$duplicates[$id]=true;
        $assignment=clientManagerField(is_array($row['fields']??null)?$row['fields']:[]);
        $found[$id]=['id'=>$id,'name'=>(string)($row['name']??''),'manager'=>$assignment['label'],'manager_conflict'=>$assignment['conflict'],'status'=>$row['fields']['status']??$row['fields']['Статус']??null,'last_purchase'=>$row['fields']['last_purchase_date']??$row['fields']['Дата последней покупки']??null];
    }
    foreach($duplicates as $id=>$_)unset($found[$id]);return $found;
}
function analyticsSourceCurrent(array $evidence,string $day,string $revision,int $now): bool {
    $verified=strtotime((string)($evidence['verified_at']??''));$expires=strtotime((string)($evidence['valid_until']??''));
    $closed=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->modify('-1 day')->format('Y-m-d');
    return ($evidence['upstream_confirmed']??false)===true&&is_string($evidence['source_revision']??null)&&$evidence['source_revision']===$revision
        &&is_string($evidence['complete_through']??null)&&$evidence['complete_through']>=$day&&$evidence['complete_through']>=$closed
        &&$day===$closed&&$verified!==false&&$verified<=$now&&$expires!==false&&$verified<$expires&&$expires>$now;
}
/** Safety gate is independent of scoring and does not invent business thresholds. */
function analyticsGate(array $event,?array $binding,?array $client,array $managers,array $policy,int $now): array {
    analyticsValidateEvent($event);$reasons=[];$run=$event['run'];$source=$run['source_decision']??[];
    if($event['mode']==='AUTOMATION_APPROVED'){
        require_once __DIR__.'/analytics_verified_approval.php';
        $proof=analyticsVerifiedApproval($event,$binding,$managers,$policy,$now);$policy=$proof['policy'];$reasons=$proof['reasons'];
    }
    if(($event['automation_allowed']??false)!==true||$event['mode']!=='AUTOMATION_APPROVED'||($run['automation_allowed']??false)!==true)$reasons[]='SHADOW_OR_AUTOMATION_NOT_APPROVED';
    if($event['signal_type']!=='CHURN_RISK')$reasons[]='PHASE1_CHURN_ONLY';
    try{$trustContract=analyticsSourceTrustContract($policy);}catch(DomainException $e){$trustContract=['method'=>null,'coverage_status'=>null,'business_freshness'=>null];$reasons[]='SOURCE_TRUST_POLICY_INVALID';}
    if($event['mode']==='AUTOMATION_APPROVED'&&(
        ($event['subject']['kind']??null)!=='external_id'||!is_array($event['assignment']??null)
        ||($event['assignment']['binding_revision']??0)<1
        ||($source['method']??null)!==$trustContract['method']
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($source['proof_hash']??''))
        ||!is_int($source['policy_revision']??null)||$source['policy_revision']<1))$reasons[]='PHASE1_APPROVAL_CONTRACT_REQUIRED';
    $scope=$run['scope']['policy']??'';
    if($scope===''||!in_array($scope,$policy['approved_scope_policies']??[],true))$reasons[]='SCOPE_NOT_APPROVED';
    $scopeHash=hash('sha256',json_encode(analyticsCanonical($run['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if(!in_array($scopeHash,$policy['approved_scope_hashes']??[],true))$reasons[]='SCOPE_FILTERS_NOT_APPROVED';
    if(($source['coverage_status']??null)!==$trustContract['coverage_status']||($source['business_freshness']??null)!==$trustContract['business_freshness']||($source['accepted_data_through']??'')<$event['as_of']||($source['effective_as_of']??null)!==$event['as_of']||($run['as_of']??null)!==$event['as_of']||($source['source_revision']??null)!==($run['source_revision']??null)
       ||!analyticsSourceCurrent($policy['sales']??[],$event['as_of'],(string)($run['source_revision']??''),$now))$reasons[]='SALES_FRESHNESS_UNCONFIRMED';
    $clientsEvidence=$policy['clientsvr']??[];
    if(!analyticsSourceCurrent($clientsEvidence,$event['as_of'],(string)($clientsEvidence['source_revision']??''),$now)||!isset($policy['cache_revision'])||($clientsEvidence['cache_revision']??null)!==$policy['cache_revision'])$reasons[]='CLIENTS_FRESHNESS_UNCONFIRMED';
    if(!analyticsBindingAssignable($binding)||empty($binding['client_id']))$reasons[]=$binding?('IDENTITY_'.$binding['status']):'IDENTITY_NOT_FOUND';
    if(!$client||!$binding||(string)$client['id']!==(string)$binding['client_id'])$reasons[]='DIRECTORY_CLIENT_NOT_FOUND_OR_AMBIGUOUS';
    if($client&&($client['manager_conflict']??false))$reasons[]='MANAGER_FIELD_CONFLICT';
    if($client&&in_array($client['status'],['archived','Архивный'],true))$reasons[]='CLIENT_ARCHIVED';
    if(!$binding||!array_key_exists('technical',$binding)||(int)$binding['technical']!==0)$reasons[]='TECHNICAL_OR_UNREVIEWED_CLIENT';
    if(($event['subject']['kind']??'')==='external_id'&&($event['subject']['key']??null)!==($client['id']??null))$reasons[]='EXTERNAL_ID_MISMATCH';
    if(isset($event['assignment'])){
        $until=strtotime((string)($source['valid_until']??''));
        if(($event['assignment']['client_id']??null)!==($client['id']??null)||$until===false||$until<=$now)$reasons[]='PRODUCER_ASSIGNMENT_OR_PROOF_INVALID';
        if(!$binding||($event['assignment']['binding_revision']??null)!==(int)$binding['revision'])$reasons[]='BINDING_REVISION_CHANGED';
    }
    $manager=resolveBoundManager((string)($client['manager']??''),$managers);
    if(isset($event['assignment'])&&($event['assignment']['manager_id']??null)!==$manager['user_id'])$reasons[]='PRODUCER_MANAGER_MISMATCH';
    if($manager['status']!=='VERIFIED')$reasons[]=$manager['status'];
    if($event['status']!=='ACTIVE')$reasons[]='SOURCE_'.$event['status'];
    if($event['last_purchase']>$event['as_of'])$reasons[]='PURCHASE_AFTER_CALCULATION';
    if($client&&!empty($client['last_purchase'])&&substr((string)$client['last_purchase'],0,10)>$event['last_purchase'])$reasons[]='NEW_PURCHASE_AFTER_SIGNAL';
    $calculated=strtotime($event['calculated_at']);$maxAge=$policy['max_signal_age_seconds']??null;
    if(!is_int($maxAge)||$maxAge<=0)$reasons[]='SIGNAL_AGE_POLICY_NOT_APPROVED';
    elseif($calculated===false||$calculated>$now||$now-$calculated>$maxAge)$reasons[]='SIGNAL_EXPIRED';
    if(($event['analytical_eligible']??false)!==true)$reasons[]='ANALYTICAL_JUSTIFICATION_MISSING';
    if(!in_array($event['generator_version']??null,$policy['approved_generators']??[],true))$reasons[]='GENERATOR_NOT_APPROVED';
    if(($policy['policy_approved']??false)!==true)$reasons[]='TASK_POLICY_NOT_APPROVED';
    if(!in_array($event['signal_type'],$policy['enabled_types']??[],true))$reasons[]='SIGNAL_TYPE_AUTOMATION_DISABLED';
    $minimum=$policy['min_confidence']??null;
    if(!is_numeric($minimum)||(float)$minimum<0||(float)$minimum>1)$reasons[]='QUALITY_POLICY_NOT_APPROVED';
    elseif((float)$event['confidence']<(float)$minimum)$reasons[]='LOW_CONFIDENCE';
    $priorities=$policy['priorities']??[];
    if(!in_array($priorities[$event['signal_type']]??null,['LOW','NORMAL','HIGH','URGENT'],true))$reasons[]='PRIORITY_POLICY_NOT_APPROVED';
    $dueDays=$policy['due_days_by_type'][$event['signal_type']]??null;
    if(($event['generator_version']??null)==='inactivity/aviator-churn-pilot/1'&&$dueDays!==3)$reasons[]='PILOT_DUE_DAYS_NOT_APPROVED';
    if(!is_int($dueDays)||$dueDays<0||$dueDays>365)$reasons[]='DUE_POLICY_NOT_APPROVED';
    return ['allowed'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'manager_id'=>$manager['user_id'],'client_id'=>$binding['client_id']??null];
}
function analyticsBinding(PDO $pdo,string $key,bool $forUpdate=false): ?array { $s=$pdo->prepare('SELECT * FROM analytics_client_bindings WHERE subject_key=?'.($forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$key]);return $s->fetch(PDO::FETCH_ASSOC)?:null; }

/** Re-evaluate replayed receipts after administrative mapping/freshness changes. */
function processAnalyticsEvent(PDO $pdo,array $event,?array $client,array $policy,string $mode,int $now): array {
    if(!in_array($mode,['disabled','dry_run','enabled'],true))throw new InvalidArgumentException('Invalid integration mode');
    if($mode==='disabled')return ['decision'=>'DISABLED','reasons'=>['INTEGRATION_DISABLED']];
    analyticsValidateEvent($event);$signalKey=analyticsSignalKey($event);$subjectKey=analyticsSubjectKey($event['subject'],$event['run']['scope']);
    $json=json_encode(analyticsCanonical($event),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
    $refreshLock=null;
    if($event['mode']==='AUTOMATION_APPROVED'){
        $refreshLock=fopen(clientsEnsureDirectory(clientsProjectStorageDirectory()).'/clients_cache_refresh.lock','c');
        if(!$refreshLock||!flock($refreshLock,LOCK_SH|LOCK_NB)){if(is_resource($refreshLock))fclose($refreshLock);throw new RuntimeException('Clients refresh in progress; retry');}
    }
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM analytics_inbox WHERE signal_key=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$signalKey]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if($old&&(int)$old['signal_revision']>$event['revision']){$pdo->commit();return ['decision'=>'OLD_REVISION','reasons'=>[]];}
        if($old&&(int)$old['signal_revision']===$event['revision']&&$old['payload_hash']!==$hash)throw new TaskConflict('Same signal revision has different content');
        if($old&&$old['subject_key']!==$subjectKey)throw new TaskConflict('Signal identity cannot change within an episode');
        // Approval is re-read under the task transaction lock, not trusted from a stale CLI config.
        $policy=array_replace($policy,analyticsTaskPolicyGateConfig(analyticsTaskPolicy($pdo,true)));
        $binding=analyticsBinding($pdo,$subjectKey,true);
        $gate=analyticsGate($event,$binding,$client,managerBindingRows($pdo,true),$policy,$now);$taskId=$old['task_id']??null;
        $review=$binding?json_decode($binding['evidence'],true):null;$peers=[];
        if(is_array($review)&&!$taskId){
            $peers=array_values(array_unique(array_filter(array_merge(array_column($review['cards']??[],'client_id'),$review['previous_client_ids']??[]),fn($id)=>is_string($id)&&$id!==($binding['client_id']??null))));
            if(count($peers)>1000)throw new RuntimeException('Historical identity group exceeds pilot bound');
            if($peers){$peer=$pdo->prepare('SELECT 1 FROM analytics_open_task_slots s JOIN manager_tasks t ON t.id=s.task_id WHERE t.client_id IN ('.implode(',',array_fill(0,count($peers),'?')).') LIMIT 1');$peer->execute($peers);
                if($peer->fetchColumn()){$gate['allowed']=false;$gate['reasons'][]='CLIENT_GROUP_HAS_OPEN_TASK_FOR_OTHER_CARD';}}
        }
        if(!$taskId&&$gate['allowed']){
            $existing=findAutomaticManagerTask($pdo,(string)$gate['client_id'],['signal_key'=>$signalKey,'evidence'=>['signal'=>$event,'identity_peer_ids'=>$peers,'matching'=>json_decode($binding['evidence'],true)['matching']??null]]);
            if($existing)$taskId=$existing['id'];
        }
        $budgetReason=null;try{$budgetReason=analyticsBudgetReason(analyticsBudget($pdo,true));}catch(Throwable $e){$budgetReason='BUDGET_UNAVAILABLE';}
        if(!$budgetReason){try{$budgetReason=analyticsDailyBudgetReason($pdo);}catch(Throwable $e){$budgetReason='DAILY_BUDGET_UNAVAILABLE';}}
        if(!$budgetReason&&!$taskId&&$gate['allowed']){
            require_once __DIR__.'/analytics_manager_pilot_budget.php';
            try{
                assertAnalyticsManagerPilotBudget($pdo,(int)$gate['manager_id']);
            }catch(AutomationBudgetBlocked $e){
                if($e->getMessage()!=='MANAGER_PILOT_BUDGET_EXHAUSTED')throw $e;
                $budgetReason=$e->getMessage();
            }
        }
        if($budgetReason&&!$taskId){$gate['allowed']=false;$gate['reasons'][]=$budgetReason;}
        $decision=$gate['allowed']?'READY':'BLOCKED';$reasons=$gate['reasons'];
        if($taskId){
            $s=$pdo->prepare('SELECT * FROM manager_tasks WHERE id=?');$s->execute([$taskId]);$task=$s->fetch(PDO::FETCH_ASSOC);
            if(!$task)throw new RuntimeException('Linked task missing');
            $decision=$gate['allowed']?'TASK_EXISTS':'REVIEW';
            if($gate['manager_id']!==null&&(int)$task['assignee_id']!==$gate['manager_id']){$decision='REVIEW';$reasons[]='MANAGER_CHANGED_REVIEW';}
            if($gate['client_id']!==null&&(string)$task['client_id']!==(string)$gate['client_id']){$decision='REVIEW';$reasons[]='CLIENT_BINDING_CHANGED_REVIEW';}
            if($event['status']!=='ACTIVE'){$decision='REVIEW';$reasons[]='SOURCE_RESOLVED_OR_SUSPENDED_REVIEW';}
            // Never auto-close, reassign, or reopen user work.
        }elseif($gate['allowed']&&$mode==='enabled'){
            $metrics=$event['metrics'];$gap=$metrics['days_without_purchase']??null;$median=$metrics['median_interval']??null;
            $deviation=is_numeric($gap)&&is_numeric($median)&&is_finite((float)$gap)&&is_finite((float)$median)?max(0,(float)$gap-(float)$median):null;
            $description='Последняя покупка: '.$event['last_purchase'].'. Без покупок: '.($gap??'неизвестно').' дн. Исторический медианный интервал: '.($median??'неизвестен').' дн. Превышение исторического интервала: '.($deviation??'неизвестно').' дн. Связаться с клиентом, выяснить причину отсутствия заказов и зафиксировать результат. Исходный сигнал: '.($event['provenance']['shadow_signal_id']??$event['signal_id']).'.';
            $due=gmdate('Y-m-d H:i:s',$now+86400*$policy['due_days_by_type'][$event['signal_type']]);
            $created=createManagerTask($pdo,[],['client_id'=>$gate['client_id'],'assignee_id'=>$gate['manager_id'],'family'=>'INACTIVITY','title'=>$event['signal_type']==='CHURN_RISK'?'Риск ухода клиента':'Просрочена ожидаемая покупка','description'=>$description,'priority'=>$policy['priorities'][$event['signal_type']],'due_at'=>$due],['allowed'=>true,'signal_key'=>$signalKey,'evidence'=>['signal'=>$event,'identity_binding_revision'=>(int)$binding['revision'],'identity_peer_ids'=>$peers,'matching'=>json_decode($binding['evidence'],true)['matching']??null,'manager_id'=>$gate['manager_id'],'catalog_revision'=>$policy['cache_revision']??null]]);
            $taskId=$created['task']['id'];$decision=$created['existing']?'TASK_EXISTS':'TASK_CREATED';
            if($created['existing']&&(int)$created['task']['assignee_id']!==$gate['manager_id']){$decision='REVIEW';$reasons[]='EXISTING_TASK_MANAGER_REVIEW';}
            if($created['existing']&&(string)$created['task']['client_id']!==$gate['client_id']){$decision='REVIEW';$reasons[]='EXISTING_TASK_OTHER_REVIEWED_CARD';}
        }elseif($gate['allowed']){$decision='DRY_RUN_READY';}
        $reasonsJson=json_encode(array_values(array_unique($reasons)),JSON_THROW_ON_ERROR);
        // dry_run persists diagnostics only; it never modifies tasks/slots/history of tasks.
        if($old)$pdo->prepare('UPDATE analytics_inbox SET signal_revision=?,payload_hash=?,payload_json=?,decision=?,reasons_json=?,task_id=?,processed_at=CURRENT_TIMESTAMP WHERE signal_key=?')->execute([$event['revision'],$hash,$json,$decision,$reasonsJson,$taskId,$signalKey]);
        else $pdo->prepare('INSERT INTO analytics_inbox(signal_key,source,signal_id,signal_revision,payload_hash,payload_json,subject_key,decision,reasons_json,task_id) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$signalKey,$event['source'],$event['signal_id'],$event['revision'],$hash,$json,$subjectKey,$decision,$reasonsJson,$taskId]);
        if(!$old||(int)$old['signal_revision']!==$event['revision']||$old['decision']!==$decision||$old['reasons_json']!==$reasonsJson||$old['task_id']!==$taskId)$pdo->prepare('INSERT INTO analytics_processing_events(signal_key,signal_revision,decision,reasons_json) VALUES(?,?,?,?)')->execute([$signalKey,$event['revision'],$decision,$reasonsJson]);
        $pdo->commit();return ['decision'=>$decision,'reasons'=>$reasons,'task_id'=>$taskId];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    finally{if(is_resource($refreshLock)){flock($refreshLock,LOCK_UN);fclose($refreshLock);}}
}
