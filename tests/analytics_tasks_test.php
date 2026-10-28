<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
require_once __DIR__.'/fixtures/analytics/phase1_event.php';
function expect(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
function denied(callable $fn,string $class):void{try{$fn();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
$pdo=analyticsTestDatabase();$pdo->exec('UPDATE analytics_task_budget SET approved=1,max_tasks=100 WHERE id=1');$admin=['id'=>1,'role'=>'admin'];$manager=['id'=>3,'role'=>'manager'];$other=['id'=>4,'role'=>'manager'];$supervisor=['id'=>2,'role'=>'supervisor'];
saveManagerBinding($pdo,$admin,'Legacy Owner',3,null);
$now=strtotime('2026-10-20T12:00:00Z');$day='2026-10-19';
$source=['coverage_status'=>'CONFIRMED','business_freshness'=>'CURRENT','accepted_data_through'=>$day,'effective_as_of'=>$day,'source_revision'=>7,'method'=>'INDEPENDENT_SIGNED_UPSTREAM_MANIFEST_V1','proof_hash'=>str_repeat('a',64),'policy_revision'=>1,'valid_until'=>'2026-10-20T15:00:00Z'];
$run=['id'=>'test-run','status'=>'SUCCEEDED','as_of'=>$day,'source_revision'=>7,'automation_allowed'=>true,'scope'=>['policy'=>'test-policy','department'=>'TEST'],'source_decision'=>$source];
$event=['source'=>'sales-journal','signal_id'=>'signal-1','revision'=>1,'status'=>'ACTIVE','signal_type'=>'CHURN_RISK','family'=>'INACTIVITY','subject'=>['kind'=>'normalized_name','version'=>1,'key'=>'test customer'],'score'=>70,'confidence'=>0.8,'last_purchase'=>'2026-09-01','as_of'=>$day,'calculated_at'=>'2026-10-20T10:00:00Z','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'analytical_eligible'=>true,'generator_version'=>'inactivity/1','metrics'=>['median_interval'=>7,'days_without_purchase'=>48],'run'=>$run];
$event=phase1ConsumerFixture($event,'client-100',3,'2026-10-20T15:00:00Z');
$evidence=['upstream_confirmed'=>true,'complete_through'=>$day,'verified_at'=>'2026-10-20T09:00:00Z','valid_until'=>'2026-10-20T15:00:00Z','source_revision'=>'7'];
$policy=['approved_scope_policies'=>['test-policy'],'approved_generators'=>['inactivity/1'],'max_signal_age_seconds'=>86400,'sales'=>$evidence,'clientsvr'=>$evidence+['cache_revision'=>'test-cache'],'cache_revision'=>'test-cache','min_confidence'=>0.5,'priorities'=>['CHURN_RISK'=>'HIGH','PURCHASE_OVERDUE'=>'NORMAL'],'due_days_by_type'=>['CHURN_RISK'=>2,'PURCHASE_OVERDUE'=>3],'policy_approved'=>true,'enabled_types'=>['CHURN_RISK','PURCHASE_OVERDUE']];
$policy['approved_scope_hashes']=[hash('sha256',json_encode(analyticsCanonical($run['scope']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
$subject=analyticsSubjectKey($event['subject'],$run['scope']);
$pdo->prepare('INSERT INTO analytics_client_bindings(subject_key,subject_json,client_id,status,technical,evidence,reviewer_id) VALUES(?,?,?,?,?,?,?)')->execute([$subject,'{}','client-100','VERIFIED',0,'Synthetic reviewed ID',1]);
$client=['id'=>'client-100','name'=>'Test Customer','manager'=>'Legacy Owner','manager_conflict'=>false,'status'=>'active'];
$binding=analyticsBinding($pdo,$subject);$aliases=managerBindingRows($pdo);
expect(!analyticsGate($event,$binding,$client,$aliases,$policy,$now)['allowed'],'Fabricated proof_hash and VERIFIED text cannot authorize automation');
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
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'HIGH','due_days'=>2],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>3]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
foreach(['dry_run','enabled'] as $mode){$out=processAnalyticsEvent($pdo,$event,$client,$policy,$mode,$now);expect($out['decision']==='BLOCKED'&&in_array('SIGNED_SOURCE_OR_REVIEW_INVALID',$out['reasons'],true),'No self-approved evidence: '.$mode);}
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Unsigned source creates zero tasks');
expect((int)analyticsBudget($pdo)['used_tasks']===0,'Unsigned source spends zero budget');
// Manual tasks deliberately remain independent of source-proof/automation readiness.
$created=createManagerTask($pdo,$admin,['client_id'=>'client-100','assignee_id'=>3,'family'=>'INACTIVITY','title'=>'Manual lifecycle fixture','priority'=>'HIGH']);$task=$created['task'];
expect(taskCanRead($manager,$task)&&!taskCanRead($other,$task)&&taskCanRead($supervisor,$task),'read scope');
denied(fn()=>updateManagerTask($pdo,$other,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Wrong owner']),DomainException::class);
denied(fn()=>updateManagerTask($pdo,$supervisor,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Read only']),DomainException::class);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'DONE','result'=>'']),InvalidArgumentException::class);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'assignee_id'=>4]),DomainException::class);
updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'IN_PROGRESS','result'=>'Contact planned']);
denied(fn()=>updateManagerTask($pdo,$manager,$task['id'],['version'=>1,'status'=>'DONE','result'=>'Stale']),TaskConflict::class);
$resolved=$event;$resolved['revision']=2;$resolved['status']='RESOLVED';processAnalyticsEvent($pdo,$resolved,$client,$policy,'enabled',$now);
expect($pdo->query('SELECT status FROM manager_tasks')->fetchColumn()==='IN_PROGRESS','Source never auto-closes work');
saveManagerBinding($pdo,$admin,'Legacy Owner',4,1);$next=$event;$next['revision']=3;processAnalyticsEvent($pdo,$next,$client,$policy,'enabled',$now);
expect((int)$pdo->query('SELECT assignee_id FROM manager_tasks')->fetchColumn()===3,'No automatic reassignment');
updateManagerTask($pdo,$manager,$task['id'],['version'=>2,'status'=>'DONE','result'=>'Contact completed']);
processAnalyticsEvent($pdo,$next,$client,$policy,'enabled',$now);
expect((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===1,'Replay never reopens completed task');
$manual=createManagerTask($pdo,$admin,['client_id'=>'client-102','assignee_id'=>3,'title'=>'Different stable ID, same contacts']);
expect($manual['task']['client_id']==='client-102','Distinct stable IDs are not merged');
denied(fn()=>createManagerTask($pdo,$manager,['client_id'=>'x','assignee_id'=>3,'title'=>'Denied']),DomainException::class);
echo "analytics_tasks_test: unsigned approval rejected; manual lifecycle/ACL/identity isolation preserved; positive native proof flow covered by verified_pipeline_e2e\n";
