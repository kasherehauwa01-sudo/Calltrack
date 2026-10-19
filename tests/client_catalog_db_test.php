<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='calltrack_max_history_test')throw new RuntimeException('Disposable fixture DB required');
require_once __DIR__.'/../api/client_catalog.php';
class CatalogCountingPDO extends PDO{
    public int $reads=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {$this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->reads++;return parent::prepare($query,$options);}
}
$pdo=new CatalogCountingPDO('mysql:host='.getenv('CALLTRACK_DB_HOST').';dbname=calltrack_max_history_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$records=clientCatalogRecords();$managers=clientCatalogManagers($pdo);$user=['id'=>1,'role'=>'manager','display_name'=>'One'];
foreach([array_slice($records,0,1,true),$records] as $set){$pdo->reads=0;clientCatalogActivity($pdo,$set,$managers,$user);if($pdo->reads!==2)throw new RuntimeException('N+1 query detected');echo 'clients='.count($set).' activity queries='.$pdo->reads.PHP_EOL;}
$activity=clientCatalogActivity($pdo,$records,$managers,$user);
if(($activity['catalog-a']??'')!=='2026-10-19 10:00:00'||isset($activity['catalog-b'])||isset($activity['catalog-none']))throw new RuntimeException('Activity/owner matching failed');
$plan=$pdo->query("EXPLAIN SELECT user_phone,phone,MAX(CONCAT(call_date,' ',call_time)) FROM calls WHERE user_phone='web-user-1' GROUP BY user_phone,phone")->fetchAll();
echo json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
echo "client_catalog_db_test: bulk activity and owner isolation OK; no schema/index changes\n";
