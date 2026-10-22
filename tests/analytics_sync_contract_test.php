<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_sync.php';
require_once __DIR__.'/fixtures/analytics/database.php';
function checkSync(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejectedPage(array $response,bool $publication):void{
    $pdo=analyticsTestDatabase();
    if(!$publication)$pdo->exec("UPDATE analytics_sync_state SET current_run_id='fixture-run',current_sequence=1 WHERE id=1");
    try{syncAnalytics($pdo,'dry_run',[],static fn()=>$response,1);throw new LogicException('Malformed page silently accepted');}
    catch(RuntimeException $e){}
    checkSync((int)$pdo->query('SELECT publication_sequence FROM analytics_sync_state')->fetchColumn()===0,'Malformed response advanced publication');
    checkSync((int)$pdo->query('SELECT COUNT(*) FROM analytics_inbox')->fetchColumn()===0,'Malformed page processed events');
    checkSync((int)$pdo->query('SELECT COUNT(*) FROM analytics_delivery_rejections')->fetchColumn()===0,'Malformed page wrote rejection before cursor validation');
}
foreach([[],['items'=>null],['items'=>'invalid'],['items'=>['named'=>[]]],['items'=>[],'next_after'=>1],['items'=>[],'next_after'=>null]] as $page){
    // The last case has no automation_allowed envelope: reject missing contract evidence too.
    rejectedPage($page,true);
}
$run=['id'=>'fixture-run','status'=>'SUCCEEDED'];
foreach([
 ['run'=>$run,'items'=>[]],
 ['run'=>$run,'items'=>[],'next_after'=>1],
 ['run'=>$run,'items'=>['named'=>[]],'next_after'=>null],
 ['run'=>$run,'items'=>[],'next_after'=>'bad'],
 ['run'=>$run,'items'=>[['score'=>'invalid']],'next_after'=>0,'automation_allowed'=>false],
] as $page)rejectedPage($page,false);
$pdo=analyticsTestDatabase();
$result=syncAnalytics($pdo,'dry_run',[],static fn()=>['items'=>[],'next_after'=>null,'automation_allowed'=>false],1);
checkSync($result['pages']===0,'Valid empty feed must stay empty');
echo "analytics_sync_contract_test: malformed pages fail before processing/checkpoint; explicit empty feed accepted OK\n";
