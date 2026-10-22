<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_integration.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function budgetCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];
$row=analyticsBudget($pdo);budgetCheck($row['max_tasks']===null&&(int)$row['approved']===0,'No implicit 10/default approval');
$input=['client_id'=>'fixture-a','assignee_id'=>3,'title'=>'Synthetic','family'=>'INACTIVITY'];
$auto=['allowed'=>true,'signal_key'=>'fixture-signal','evidence'=>[]];
try{createManagerTask($pdo,[],$input,$auto);throw new LogicException('Unapproved budget accepted');}catch(AutomationBudgetBlocked $e){}
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>2]);
$first=createManagerTask($pdo,[],$input,$auto);
$again=createManagerTask($pdo,[],$input,$auto);
budgetCheck($first['task']['id']===$again['task']['id'],'Replay returns same task');
budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Replay does not consume');
// Inject a storage failure after reservation: task + counter must roll back together.
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
 $pdo->exec("CREATE TRIGGER fail_task BEFORE INSERT ON manager_tasks WHEN NEW.client_id='fixture-b' BEGIN SELECT RAISE(ABORT,'test failure'); END");
 try{createManagerTask($pdo,[],array_replace($input,['client_id'=>'fixture-b']),array_replace($auto,['signal_key'=>'second']));throw new LogicException('Expected failure');}catch(PDOException $e){}
 budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Failed insertion refunds by rollback');
}
try{saveAnalyticsBudget($pdo,$admin,['revision'=>2,'approved'=>true,'max_tasks'=>0]);throw new LogicException('Consumed budget reset');}catch(InvalidArgumentException $e){}
saveAnalyticsBudget($pdo,$admin,['revision'=>2,'approved'=>false,'max_tasks'=>2]);
try{createManagerTask($pdo,[],array_replace($input,['client_id'=>'fixture-c']),array_replace($auto,['signal_key'=>'third']));throw new LogicException('Revoked budget accepted');}catch(AutomationBudgetBlocked $e){}
budgetCheck((int)analyticsBudget($pdo)['used_tasks']===1,'Revocation retains lifetime count');
echo "analytics_budget_test: NULL default, explicit approval/revoke, replay, rollback and no reset OK\n";
