<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function expect(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
function denied(callable $fn,string $class):void{try{$fn();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];$manager=['id'=>3,'role'=>'manager'];$other=['id'=>4,'role'=>'manager'];$supervisor=['id'=>2,'role'=>'supervisor'];
saveManagerBinding($pdo,$admin,'Legacy Owner',3,null);
$now=strtotime('2026-10-20T12:00:00Z');$day='2026-10-19';
$source=['coverage_status'=>'CONFIRMED','business_freshness'=>'CURRENT','accepted_data_through'=>$day,'effective_as_of'=>$day,'source_revision'=>7,'method'=>'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','proof_hash'=>str_repeat('a',64),'policy_revision'=>1,'valid_until'=>'2026-10-20T15:00:00Z'];
$run=['id'=>'test-run','status'=>'SUCCEEDED','as_of'=>$day,'source_revision'=>7,'automation_allowed'=>true,'scope'=>['policy'=>'test-policy','department'=>'TEST'],'source_decision'=>$source];
$event=['source'=>'sales-journal','signal_id'=>'signal-1','revision'=>1,'status'=>'ACTIVE','signal_type'=>'CHURN_RISK','family'=>'INACTIVITY','subject'=>['kind'=>'external_id','version'=>1,'key'=>'client-100'],'score'=>70,'confidence'=>0.8,'last_purchase'=>'2026-09-01','as_of'=>$day,'calculated_at'=>'2026-10-20T10:00:00Z','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'analytical_eligible'=>true,'generator_version'=>'inactivity/1','metrics'=>['median_interval'=>7,'days_without_purchase'=>48],'assignment'=>['client_id'=>'client-100','manager_id'=>3,'binding_revision'=>1],'run'=>$run];
$evidence=['upstream_confirmed'=>true,'complete_through'=>$day,'verified_at'=>'2026-10-20T09:00:00Z','valid_until'=>'2026-10-20T15:00:00Z','source_revision'=>'7'];
$policy=['approved_scope_policies'=>['test-policy'],'approved_generators'=>['inactivity/1'],'max_signal_age_seconds'=>86400,'sales'=>$evidence,'clientsvr'=>$evidence+['cache_revision'=>'test-cache'],'cache_revision'=>'test-cache','min_confidence'=>0.5,'priorities'=>['CHURN_RISK'=>'HIGH','PURCHASE_OVERDUE'=>'NORMAL'],'due_days_by_type'=>['CHURN_RISK'=>2,'PURCHASE_OVERDUE'=>3],'policy_approved'=>true,'enabled_types'=>['CHURN_RISK','PURCHASE_OVERDUE']];
$policy['approved_scope_hashes']=[hash('sha256',json_encode(analyticsCanonical($run['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
$subject=analyticsSubjectKey($event['subject'],$run['scope']);
$pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$subject,'{}','client-100','VERIFIED',0,'Synthetic reviewed ID',1]);
$client=['id'=>'client-100','name'=>'Test Customer','manager'=>'Legacy Owner','manager_conflict'=>false,'status'=>'active'];
$binding=analyticsBinding($pdo,$subject);$aliases=managerBindingRows($pdo);
expect(analyticsGate($event,$binding,$client,$aliases,$policy,$now)['allowed'],'all explicit evidence permits test event');
foreach([
 'stale'=>static function(&$e,&$b,&$c,&$p){$p['sales']['valid_until']='2026-10-20T11:00:00Z';},
 'unknown coverage'=>static function(&$e,&$b,&$c,&$p){$e['run']['source_decision']['coverage_status']='UNKNOWN';},
 'shadow'=>static function(&$e,&$b,&$c,&$p){$e['automation_allowed']=false;$e['mode']='SHADOW';},
 'identity'=>static function(&$e,&$b,&$c,&$p){$b['status']='AMBIGUOUS';},
 'missing client'=>static function(&$e,&$b,&$c,&$p){$c=null;},
 'unmapped'=>static function(&$e,&$b,&$c,&$p){$c['manager']='No binding';},
 'missing manager'=>static function(&$e,&$b,&$c,&$p){$c['manager']='';},
 'technical'=>static function(&$e,&$b,&$c,&$p){$b['technical']=1;},
 'cache revision'=>static function(&$e,&$b,&$c,&$p){$p['cache_revision']='different';},
 'insufficient justification'=>static function(&$e,&$b,&$c,&$p){$e['analytical_eligible']=false;},
 'quality'=>static function(&$e,&$b,&$c,&$p){$e['confidence']=0.1;},
 'new purchase'=>static function(&$e,&$b,&$c,&$p){$c['last_purchase']='2026-10-19';},
 'resolved'=>static function(&$e,&$b,&$c,&$p){$e['status']='RESOLVED';},
 'scope'=>static function(&$e,&$b,&$c,&$p){$p['approved_scope_policies']=[];},
 'missing business policy'=>static function(&$e,&$b,&$c,&$p){unset($p['priorities']);},
] as $label=>$mutate){$e=$event;$b=$binding;$c=$client;$p=$policy;$mutate($e,$b,$c,$p);expect(!analyticsGate($e,$b,$c,$aliases,$p,$now)['allowed'],$label.' must block');}
$inactive=$aliases;$inactive[0]['is_active']=0;expect(!analyticsGate($event,$binding,$client,$inactive,$policy,$now)['allowed'],'inactive blocks');
expect(!analyticsGate($event,$binding,$client,$aliases,[],$now)['allowed'],'no config blocks');
expect(processAnalyticsEvent($pdo,$event,$client,$policy,'disabled',$now)['decision']==='DISABLED','disabled');
expect((int)$pdo->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn()===0,'disabled does not write');
$unapproved=processAnalyticsEvent($pdo,$event,$client,$policy,'enabled',$now);expect($unapproved['decision']==='BLOCKED'&&in_array('TASK_POLICY_NOT_APPROVED',$unapproved['reasons'],true),'enabled cannot bypass unapproved persisted policy');
// Approve only synthetic test policy in this disposable DB. Runtime cannot trust an injected policy flag.
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>2],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>true,'priority'=>'NORMAL','due_days'=>3]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);


expect(processAnalyticsEvent($pdo,$event,$client,$policy,'dry_run',$now)['decision']==='DRY_RUN_READY','dry run eligibility');
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'dry_run never tasks');
$first=processAnalyticsEvent($pdo,$event,$client,$policy,'enabled',$now);expect($first['decision']==='TASK_CREATED','created correct task');
$task=$pdo->query('SELECT * FROM manager_tasks')->fetch(PDO::FETCH_ASSOC);expect((int)$task['assignee_id']===3&&$task['client_id']==='client-100','correct stable owner/client');
$renamed=$client;$renamed['name']='Renamed same stable ID';$renamed['phones']=['9000000000','9000000001'];expect(analyticsGate($event,$binding,$renamed,$aliases,$policy,$now)['allowed'],'name/phone changes do not change stable identity');
expect(taskCanRead($manager,$task)&&!taskCanRead($other,$task)&&taskCanRead($supervisor,$task),'read scope');
$replay=processAnalyticsEvent($pdo,$event,$client,$policy,'enabled',$now);expect($replay['task_id']===$first['task_id'],'replay same task');
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'no duplicate on replay');
$changed=$event;$changed['score']=71;denied(fn()=>processAnalyticsEvent($pdo,$changed,$client,$policy,'enabled',$now),TaskConflict::class);
$second=$event;$second['signal_id']='signal-2';$existing=processAnalyticsEvent($pdo,$second,$client,$policy,'enabled',$now);expect($existing['decision']==='TASK_EXISTS'&&$existing['task_id']===$first['task_id'],'same issue slot across episodes');
denied(fn()=>updateManagerTask($pdo,$other,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Wrong owner']),DomainException::class);
denied(fn()=>updateManagerTask($pdo,$supervisor,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Read only']),DomainException::class);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'DONE','result'=>'']),InvalidArgumentException::class);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'assignee_id'=>4]),DomainException::class);
updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'IN_PROGRESS','result'=>'Contact planned']);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Stale update']),TaskConflict::class);
$resolved=$event;$resolved['revision']=2;$resolved['status']='RESOLVED';$out=processAnalyticsEvent($pdo,$resolved,$client,$policy,'enabled',$now);expect($out['decision']==='REVIEW','source resolution review');
expect($pdo->query('SELECT status FROM manager_tasks')->fetchColumn()==='IN_PROGRESS','no automatic closure');
saveManagerBinding($pdo,$admin,'Legacy Owner',4,1);
$next=$event;$next['revision']=3;$out=processAnalyticsEvent($pdo,$next,$client,$policy,'enabled',$now);expect(in_array('MANAGER_CHANGED_REVIEW',$out['reasons'],true),'owner change review');
expect((int)$pdo->query('SELECT assignee_id FROM manager_tasks')->fetchColumn()===3,'no automatic reassignment');
updateManagerTask($pdo,$manager,$task['id'],['version'=>2,'status'=>'DONE','result'=>'Customer contacted; order planned']);
processAnalyticsEvent($pdo,$next,$client,$policy,'enabled',$now);
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'completed same episode never reopened');
$third=$event;$third['signal_id']='signal-3';$third['assignment']['manager_id']=4;$out=processAnalyticsEvent($pdo,$third,$client,$policy,'enabled',$now);expect($out['decision']==='TASK_CREATED','future episode separate task');
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===2,'two historical tasks');
// Duplicate names/phones never participate: no verified subject binding => blocked.
$ambiguous=$event;$ambiguous['signal_id']='unbound';$ambiguous['subject']['key']='same phone different client';
$out=processAnalyticsEvent($pdo,$ambiguous,$client,$policy,'enabled',$now);expect(in_array('IDENTITY_NOT_FOUND',$out['reasons'],true),'shared phone cannot infer identity');
// Same contacts/name, distinct verified IDs: never merge task subjects.
$distinct=$event;$distinct['signal_id']='distinct-stable-client';$distinct['subject']['key']='client-102';$distinct['assignment']['client_id']='client-102';$distinct['assignment']['manager_id']=4;
$distinctKey=analyticsSubjectKey($distinct['subject'],$run['scope']);
$pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$distinctKey,'{}','client-102','VERIFIED',0,'Separate reviewed ID despite same phone/name',1]);
$separate=$client;$separate['id']='client-102';$separate['phones']=['9000000000'];
$out=processAnalyticsEvent($pdo,$distinct,$separate,$policy,'enabled',$now);expect($out['decision']==='TASK_CREATED','distinct stable client must not merge by phone/name');
$check=$pdo->prepare('SELECT client_id FROM manager_tasks WHERE id=?');$check->execute([$out['task_id']]);expect($check->fetchColumn()==='client-102','distinct ID retained');
$manual=createManagerTask($pdo,$admin,['client_id'=>'client-101','assignee_id'=>3,'title'=>'Manual test','description'=>'Review','priority'=>'NORMAL']);expect(!$manual['existing'],'manual creation');
$pdo->exec('UPDATE web_users SET is_active=0 WHERE id=3');updateManagerTask($pdo,$admin,$manual['task']['id'],['version'=>1,'status'=>'CANCELLED','result'=>'Assigned user inactive; cancelled manually']);$pdo->exec('UPDATE web_users SET is_active=1 WHERE id=3');
denied(fn()=>createManagerTask($pdo,$manager,['client_id'=>'x','assignee_id'=>3,'title'=>'Denied']),DomainException::class);
$draft=['revision'=>3,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>false,'priority'=>'HIGH','due_days'=>2],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>true,'priority'=>'NORMAL','due_days'=>3]]];
saveAnalyticsTaskPolicy($pdo,$admin,$draft);saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>4,'action'=>'approve','confirm_business_approval'=>true]);
$fourth=$event;$fourth['signal_id']='disabled-type';$out=processAnalyticsEvent($pdo,$fourth,$client,$policy,'enabled',$now);expect(in_array('SIGNAL_TYPE_AUTOMATION_DISABLED',$out['reasons'],true),'per-type disabled blocks even approved overall policy');
echo "analytics_tasks_test: migration + gates + lifecycle + idempotency + ACL OK (".$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
