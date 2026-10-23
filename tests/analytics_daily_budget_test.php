<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_integration.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function dailyCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=analyticsTestDatabase();
$before=new DateTimeImmutable('2020-10-23T20:59:59Z');$after=new DateTimeImmutable('2020-10-23T21:00:00Z');
dailyCheck(analyticsBusinessDate($before)==='2020-10-23'&&analyticsBusinessDate($after)==='2020-10-24','Moscow midnight');
dailyCheck(analyticsPilotDue($before,3)==='2020-10-26 20:59:59'&&analyticsPilotDue($after,3)==='2020-10-26 21:00:00','Three calendar days');
for($i=0;$i<5;$i++){$pdo->beginTransaction();reserveAnalyticsDailyBudget($pdo,$before);$pdo->commit();}
$pdo->beginTransaction();dailyCheck(analyticsDailyBudgetReason($pdo,$before)==='DAILY_BUDGET_EXHAUSTED','Sixth blocked');
try{reserveAnalyticsDailyBudget($pdo,$before);throw new LogicException('Sixth accepted');}catch(AutomationBudgetBlocked $e){dailyCheck($e->getMessage()==='DAILY_BUDGET_EXHAUSTED','Reason');}$pdo->rollBack();
$pdo->beginTransaction();reserveAnalyticsDailyBudget($pdo,$after);$pdo->rollBack();
dailyCheck((int)$pdo->query("SELECT COUNT(*) FROM analytics_daily_budget WHERE business_date='2020-10-24'")->fetchColumn()===0,'Rollback refunds bucket');
$pdo->beginTransaction();reserveAnalyticsDailyBudget($pdo,$after);$pdo->commit();
dailyCheck((int)$pdo->query("SELECT used_tasks FROM analytics_daily_budget WHERE business_date='2020-10-23'")->fetchColumn()===5,'New day retains old history');
$admin=['id'=>1,'role'=>'admin'];
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>.5,'types'=>[['signal_type'=>'CHURN_RISK','enabled'=>true,'priority'=>'NORMAL','due_days'=>3],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>10]);
function dailyCreate(PDO $pdo,int $n):array {
 $id='daily-client-'.$n;$manager=$n%2?3:4;
 return createManagerTask($pdo,[],['client_id'=>$id,'assignee_id'=>$manager,'family'=>'INACTIVITY','title'=>'Synthetic'],['allowed'=>true,'signal_key'=>'daily-signal-'.$n,'evidence'=>['signal'=>['signal_type'=>'CHURN_RISK','generator_version'=>'inactivity/aviator-churn-pilot/1','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'status'=>'ACTIVE','subject'=>['kind'=>'external_id','key'=>$id],'assignment'=>['client_id'=>$id,'manager_id'=>$manager]]]]);
}
$pdo->exec("UPDATE analytics_task_type_policy SET due_days=1 WHERE signal_type='CHURN_RISK'");
try{dailyCreate($pdo,90);throw new LogicException('Wrong pilot due allowed');}catch(DomainException $e){}
dailyCheck((int)analyticsBudget($pdo)['used_tasks']===0,'Wrong due rolls budgets back');
$pdo->exec("UPDATE analytics_task_type_policy SET due_days=3 WHERE signal_type='CHURN_RISK'");
for($i=0;$i<5;$i++)dailyCreate($pdo,$i);
dailyCheck(dailyCreate($pdo,0)['existing'],'Replay at exhausted cap succeeds');
try{dailyCreate($pdo,6);throw new LogicException('Sixth task accepted');}catch(AutomationBudgetBlocked $e){}
dailyCheck((int)analyticsBudget($pdo)['used_tasks']===5,'Lifetime and daily rollback together');
$used=$pdo->query('SELECT sum(used_tasks) FROM analytics_daily_budget')->fetchColumn();
createManagerTask($pdo,$admin,['client_id'=>'manual','assignee_id'=>3,'title'=>'Manual']);
dailyCheck($pdo->query('SELECT sum(used_tasks) FROM analytics_daily_budget')->fetchColumn()===$used,'Manual exempt');
$pdo->exec('DROP TABLE analytics_daily_budget_reservations');
$pdo->exec('DROP TABLE analytics_daily_budget');
$pdo->exec('DELETE FROM analytics_daily_budget_policy WHERE id=1');
try{$pdo->beginTransaction();reserveAnalyticsDailyBudget($pdo);throw new LogicException('Missing policy accepted');}catch(AutomationBudgetBlocked $e){$pdo->rollBack();}
echo "analytics_daily_budget_test: Moscow boundary, five/global, replay, rollback, manual, missing policy OK\n";
