<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_integration.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function budgetCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];
$row=analyticsBudget($pdo);budgetCheck($row['max_tasks']===null&&(int)$row['approved']===0,'No implicit 10/default approval');
$input=['client_id'=>'fixture-a','assignee_id'=>3,'title'=>'Synthetic','family'=>'INACTIVITY'];
$auto=['allowed'=>true,'signal_key'=>'fixture-signal','evidence'=>['signal'=>['signal_type'=>'CHURN_RISK','last_purchase'=>'2020-01-01','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'status'=>'ACTIVE','subject'=>['kind'=>'external_id','key'=>'fixture-a'],'assignment'=>['client_id'=>'fixture-a','manager_id'=>3]]]];
// Even an approved budget and forged allowed flag cannot bypass a revoked task policy.
$pdo->exec('UPDATE analytics_task_budget SET approved=1,max_tasks=2 WHERE id=1');
try{createManagerTask($pdo,[],$input,$auto);throw new LogicException('Unapproved policy accepted');}catch(DomainException $e){}
budgetCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()===0,'Direct caller cannot bypass policy');
$pdo->exec('UPDATE analytics_task_budget SET approved=0,max_tasks=NULL WHERE id=1');
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>0.5,'types'=>[['signal_type'=>'CHURN_RISK','last_purchase'=>'2020-01-01','enabled'=>true,'priority'=>'HIGH','due_days'=>1],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
try{createManagerTask($pdo,[],$input,$auto);throw new LogicException('Unapproved budget accepted');}catch(AutomationBudgetBlocked $e){}
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>2]);
$first=createManagerTask($pdo,[],$input,$auto);
$again=createManagerTask($pdo,[],$input,$auto);
budgetCheck($first['task']['id']===$again['task']['id'],'Replay returns same task');
budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Replay does not consume');
// Inject a storage failure after reservation: task + counter must roll back together.
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
 $pdo->exec("CREATE TRIGGER fail_task BEFORE INSERT ON manager_tasks WHEN NEW.client_id='fixture-b' BEGIN SELECT RAISE(ABORT,'test failure'); END");
 try{createManagerTask($pdo,[],array_replace($input,['client_id'=>'fixture-b']),array_replace_recursive($auto,['signal_key'=>'second','evidence'=>['signal'=>['subject'=>['key'=>'fixture-b'],'assignment'=>['client_id'=>'fixture-b']]]]));throw new LogicException('Expected failure');}catch(PDOException $e){}
 budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Failed insertion refunds by rollback');
}
try{saveAnalyticsBudget($pdo,$admin,['revision'=>2,'approved'=>true,'max_tasks'=>0]);throw new LogicException('Consumed budget reset');}catch(InvalidArgumentException $e){}
saveAnalyticsBudget($pdo,$admin,['revision'=>2,'approved'=>false,'max_tasks'=>2]);
try{createManagerTask($pdo,[],array_replace($input,['client_id'=>'fixture-c']),array_replace_recursive($auto,['signal_key'=>'third','evidence'=>['signal'=>['subject'=>['key'=>'fixture-c'],'assignment'=>['client_id'=>'fixture-c']]]]));throw new LogicException('Revoked budget accepted');}catch(AutomationBudgetBlocked $e){}
budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Revocation retains lifetime count');
$pdo->exec("UPDATE analytics_task_type_policy SET enabled=0 WHERE signal_type='CHURN_RISK'");
try{createManagerTask($pdo,[],$input,$auto);throw new LogicException('Disabled type accepted');}catch(DomainException $e){}
echo "analytics_budget_test: NULL default, explicit approval/revoke, replay, rollback and no reset OK\n";
