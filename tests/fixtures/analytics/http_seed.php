<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_DB_NAME')!=='analytics_http_test')throw new RuntimeException('Isolated HTTP fixture required');
require_once __DIR__.'/../../../api/analytics_sync.php';
require_once __DIR__.'/database.php';
$directoryOnly=getenv('ANALYTICS_FIXTURE_DIRECTORY_ONLY')==='1';
if(!$directoryOnly)$pdo=analyticsTestDatabase();
$directory=clientsOpenSqlite();
foreach([
 ['id'=>'client-100','name'=>'Synthetic Customer','phones'=>['9000000000'],'fields'=>['manager'=>'Legacy Owner','status'=>'active']],
 ['id'=>'client-101','name'=>'Other Synthetic Customer','phones'=>['9000000000'],'fields'=>['Менеджер'=>'Other Owner','status'=>'active']],
] as $client)clientsSqliteUpsert($directory,$client,$client['id']);
if(!$directoryOnly){
$event=json_decode(file_get_contents(getenv('ANALYTICS_ENGINE_EVENT_JSON')),true,64,JSON_THROW_ON_ERROR);
processAnalyticsEvent($pdo,$event,null,[],'dry_run',time());
}
echo "HTTP fixture seeded: synthetic clients only\n";
