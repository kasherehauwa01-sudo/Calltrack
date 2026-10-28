<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_integration.php';
require_once __DIR__.'/fixtures/analytics/database.php';
$dsn=getenv('ANALYTICS_TASK_TEST_DSN');
if($dsn!=='mysql:host=analytics-test-mariadb;dbname=analytics_race_test')throw new RuntimeException('Isolated daily race database required');
function raceTask(PDO $pdo,int $n):array {
 $id='race-'.$n;$manager=$n%2?3:4;
 return createManagerTask($pdo,[],['client_id'=>$id,'assignee_id'=>$manager,'title'=>'Synthetic','family'=>'INACTIVITY'],['allowed'=>true,'signal_key'=>hash('sha256',$id),'evidence'=>['signal'=>['signal_type'=>'CHURN_RISK','last_purchase'=>'2020-01-01','generator_version'=>'inactivity/aviator-churn-pilot/1','mode'=>'AUTOMATION_APPROVED','automation_allowed'=>true,'status'=>'ACTIVE','subject'=>['kind'=>'external_id','key'=>$id],'assignment'=>['client_id'=>$id,'manager_id'=>$manager]]]]);
}
if(($argv[1]??'')==='worker'){
 $pdo=new PDO($dsn,getenv('ANALYTICS_TASK_TEST_USER'),getenv('ANALYTICS_TASK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 for($n=0;$n<5;$n++)try{raceTask($pdo,(int)$argv[2]);exit(0);}catch(AutomationBudgetBlocked $e){if($e->getMessage()!=='DAILY_BUDGET_EXHAUSTED')throw $e;exit(0);}catch(PDOException $e){if(!in_array($e->getCode(),['40001','23000'],true))throw $e;usleep(20000);}
 exit(1);
}
$pdo=analyticsTestDatabase();$admin=['id'=>1,'role'=>'admin'];
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>1,'action'=>'save_draft','min_confidence'=>.3,'types'=>[['signal_type'=>'CHURN_RISK','last_purchase'=>'2020-01-01','enabled'=>true,'priority'=>'NORMAL','due_days'=>3],['signal_type'=>'PURCHASE_OVERDUE','enabled'=>false,'priority'=>null,'due_days'=>null]]]);
saveAnalyticsTaskPolicy($pdo,$admin,['revision'=>2,'action'=>'approve','confirm_business_approval'=>true]);
saveAnalyticsBudget($pdo,$admin,['revision'=>1,'approved'=>true,'max_tasks'=>100]);
$pdo->exec("CREATE TRIGGER fail_daily_test BEFORE INSERT ON manager_tasks FOR EACH ROW BEGIN IF NEW.client_id='race-99' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic insert failure'; END IF; END");
try{raceTask($pdo,99);throw new LogicException('Failure not raised');}catch(PDOException $e){if($e->getCode()!=='45000')throw $e;}
if((int)analyticsBudget($pdo)['used_tasks']!==0||(int)$pdo->query('SELECT COUNT(*) FROM analytics_daily_budget_reservations')->fetchColumn()!==0)throw new RuntimeException('Rollback spent quota');
$jobs=[];foreach([0,0,1,2,3,4,5,6] as $i){$pipes=[];$jobs[]=proc_open([PHP_BINARY,__FILE__,'worker',(string)$i],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
foreach($jobs as $job)if(proc_close($job)!==0)throw new RuntimeException('Worker failed');
foreach(['manager_tasks','analytics_daily_budget_reservations'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==5)throw new RuntimeException('Daily cap violated: '.$table);
if((int)analyticsBudget($pdo)['used_tasks']!==5)throw new RuntimeException('Lifetime differs');
$bad=$pdo->query("SELECT COUNT(*) FROM manager_tasks WHERE TIMESTAMPDIFF(SECOND,created_at,due_at) NOT BETWEEN 259199 AND 259201")->fetchColumn();if((int)$bad!==0)throw new RuntimeException('Due not three days');
$pdo->exec('UPDATE analytics_daily_budget SET used_tasks=0');
try{$pdo->beginTransaction();reserveAnalyticsDailyBudget($pdo);throw new LogicException('Corrupt quota accepted');}catch(AutomationBudgetBlocked $e){if($e->getMessage()!=='DAILY_BUDGET_INVALID')throw $e;$pdo->rollBack();}
echo "analytics_daily_concurrency_test: eight workers/two managers => five tasks, replay, native rollback, due +3, corruption blocked OK\n";
