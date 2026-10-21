<?php
declare(strict_types=1);
require_once __DIR__.'/../api/manager_tasks_service.php';
require_once __DIR__.'/fixtures/analytics/database.php';
$dsn=getenv('ANALYTICS_TASK_TEST_DSN');
if($dsn!=='mysql:host=analytics-test-mariadb;dbname=analytics_race_test')throw new RuntimeException('Isolated race fixture required');
if(($argv[1]??'')==='worker'){
    $pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    for($i=0;$i<3;$i++)try{
        createManagerTask($pdo,['id'=>1,'role'=>'admin'],['client_id'=>'synthetic-race-client','assignee_id'=>(int)$argv[2],'family'=>'INACTIVITY','title'=>'Synthetic concurrent task']);exit(0);
    }catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','40001'],true))throw $e;usleep(100000);}
    exit(1);
}
$pdo=analyticsTestDatabase();
$pdo->exec('CREATE TRIGGER slow_test_task BEFORE INSERT ON manager_tasks FOR EACH ROW DO SLEEP(0.2)');
$processes=[];
foreach([3,4] as $manager){$pipes=[];$processes[]=proc_open([PHP_BINARY,__FILE__,'worker',(string)$manager],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);}
foreach($processes as $process)if(proc_close($process)!==0)throw new RuntimeException('Concurrent writer did not recover');
if((int)$pdo->query('SELECT COUNT(*) FROM manager_tasks')->fetchColumn()!==1||(int)$pdo->query('SELECT COUNT(*) FROM analytics_open_task_slots')->fetchColumn()!==1)throw new RuntimeException('Concurrent duplicate');
echo "analytics_concurrency_test: concurrent creators, unique slot and retry => exactly one task OK\n";
