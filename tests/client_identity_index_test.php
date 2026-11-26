<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_catalog.php';
require_once __DIR__.'/../api/clients_cache_refresh.php';
require_once __DIR__.'/../api/sales_buyer_catalog.php';

function indexExpect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function indexUnavailable(callable $f):void {
    try{$f();throw new LogicException('Unavailable index was accepted');}
    catch(ClientIdentityIndexUnavailable $e){}
}
function indexDenied(callable $f):void {
    try{$f();throw new LogicException('Ambiguous/missing ID was accepted');}
    catch(ClientCatalogAccessDenied $e){}
}
if(($argv[1]??'')==='probe'){
    $start=microtime(true);
    indexUnavailable(static fn()=>clientCatalogLookup('a'));
    indexExpect(microtime(true)-$start<1,'Reader waited for publication');
    echo "reader blocked safely\n";exit;
}
$root=sys_get_temp_dir().'/identity-test-'.bin2hex(random_bytes(8));
putenv('CALLTRACK_STORAGE_DIR='.$root);putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
$rows=[
 ['id'=>'a','name'=>'  Иван   Иванов ','phones'=>['89990000001','89990000002'],'fields'=>['email'=>'SAME@example.test; own@example.test','Менеджер'=>'One']],
 ['id'=>'b','name'=>'иван иванов','phones'=>['+79990000001'],'fields'=>['email'=>'same@example.test','Менеджер'=>'Two']],
 ['id'=>'c','name'=>'Unique','phones'=>['89990000003'],'fields'=>['email'=>'unique@example.test','Менеджер'=>'One']],
];
writeClientsCache($rows);
$a=clientCatalogLookup('a');
indexExpect(!$a['name_unique'],'Normalized duplicate name is ambiguous');
indexExpect(array_map('strval',array_keys($a['phone_index']))===['9990000002'],'Shared phone is excluded, private phone retained');
indexExpect(isset($a['email_index']['own@example.test'])&&!isset($a['email_index']['same@example.test']),'Shared email excluded');
foreach($rows as $row){
    $reference=clientCatalogLookup($row['id'],static fn()=>$rows);
    indexExpect(clientCatalogLookup($row['id'])===$reference,'Indexed identity differs from exhaustive reference');
}
indexDenied(static fn()=>clientCatalogLookup('absent'));
$managers=[['id'=>1,'display_name'=>'One'],['id'=>2,'display_name'=>'Two']];
indexExpect(clientCatalogVisible(['id'=>1,'role'=>'manager','display_name'=>'One'],$a['record'],$managers),'Owner denied');
indexExpect(!clientCatalogVisible(['id'=>2,'role'=>'manager','display_name'=>'Two'],$a['record'],$managers),'Foreign manager allowed');
$conflict=$a['record'];$conflict['manager_conflict']=true;
indexExpect(!clientCatalogVisible(['id'=>1,'role'=>'manager','display_name'=>'One'],$conflict,$managers),'Conflicting assignment allowed');
$contact=salesBuyerContact(['sales_client_key'=>'unique','owner_id'=>1,'unique_phones'=>['9990000003']],$managers);
indexExpect($contact['phones']===['9990000003'],'Buyer fast path lost safe phone');
$ambiguous=salesBuyerContact(['sales_client_key'=>'иван   иванов','owner_id'=>1,'unique_phones'=>['9990000002']],$managers);
indexExpect($ambiguous===['client_id'=>'','phones'=>[],'emails'=>[]],'Normalized namesake was linked through buyer name');
$metaFile=clientIdentityDirectory().'/current.json';$original=file_get_contents($metaFile);
$meta=json_decode($original,true,512,JSON_THROW_ON_ERROR);
foreach(['records-a','keys-name:иван иванов'] as $selector){
    [$kind,$key]=explode('-',$selector,2);
    $file=clientIdentityDirectory().'/'.$meta['generation'].'/'.$kind.'-'.clientIdentityCode($key).'.json';
    $saved=file_get_contents($file);file_put_contents($file,'{}');
    indexUnavailable(static fn()=>clientCatalogLookup('a'));file_put_contents($file,$saved);
}
unlink($metaFile);indexUnavailable(static fn()=>clientCatalogLookup('a'));file_put_contents($metaFile,$original);
file_put_contents($metaFile,'{');indexUnavailable(static fn()=>clientCatalogLookup('a'));file_put_contents($metaFile,$original);
$wrong=$meta;$wrong['version']=999;file_put_contents($metaFile,json_encode($wrong));
indexUnavailable(static fn()=>clientCatalogLookup('a'));file_put_contents($metaFile,$original);
$wrong=$meta;$wrong['source']='wrong';file_put_contents($metaFile,json_encode($wrong));
indexUnavailable(static fn()=>clientCatalogLookup('a'));file_put_contents($metaFile,$original);
file_put_contents(clientsCacheFile()," ",FILE_APPEND);
indexUnavailable(static fn()=>clientCatalogLookup('a'));
writeClientsCache($rows);

// All competing processes must fail quickly while a generation is being published.
$lock=clientIdentityLock(true);
$process=proc_open([PHP_BINARY,__FILE__,'probe'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
indexExpect(is_resource($process),'Cannot spawn concurrent reader');
$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
indexExpect(proc_close($process)===0,'Concurrent reader failed: '.$output.$error);
indexUnavailable(static fn()=>clientIdentityMutation(static fn()=>null));
flock($lock,LOCK_UN);fclose($lock);

// Use the same file-backed full publication and delta path as production.
$stream=clientsStreamingCacheCreate();clientsStreamingCacheAppend($stream,$rows);clientsStreamingCachePublish($stream);
$updated=['id'=>'a','name'=>'Renamed','phones'=>$rows[0]['phones'],'email'=>'same@example.test; own@example.test','Менеджер'=>'Two'];
$page=['status'=>'success','items'=>[['change_id'=>1,'operation'=>'upsert','client_id'=>'a','client'=>$updated]],'next_after_id'=>1,'has_more'=>false];
applyClientsDeltaPage($page,0,static fn()=>indexUnavailable(static fn()=>clientCatalogLookup('a')));
$after=clientCatalogLookup('a');
indexExpect($after['record']['name']==='Renamed'&&$after['name_unique'],'Delta index remained stale');
indexExpect(!clientCatalogVisible(['id'=>1,'role'=>'manager','display_name'=>'One'],$after['record'],$managers),'Old manager retained access after delta');
indexExpect(clientCatalogVisible(['id'=>2,'role'=>'manager','display_name'=>'Two'],$after['record'],$managers),'New manager cannot access client');
$page['items'][0]['change_id']=2;$page['next_after_id']=2;
try{applyClientsDeltaPage($page,1,static fn()=>throw new RuntimeException('crash'));throw new LogicException('Missing crash');}
catch(RuntimeException $e){indexExpect($e->getMessage()==='crash','Unexpected crash result');}
indexUnavailable(static fn()=>clientCatalogLookup('a'));
$different=$page;$different['items'][0]['client']['name']='Wrong replay';
foreach([static fn()=>applyClientsDeltaPage($different,1),static fn()=>runClientsDeltaPages(2,static fn()=>['items'=>[],'next_after_id'=>2,'has_more'=>false])] as $wrongReplay){
    try{$wrongReplay();throw new LogicException('Partial delta certified by a different process/page');}
    catch(RuntimeException $e){}
    indexUnavailable(static fn()=>clientCatalogLookup('a'));
}
applyClientsDeltaPage($page,1);indexExpect(clientCatalogLookup('a')['record']['name']==='Renamed','Idempotent delta recovery failed');
$delete=['status'=>'success','items'=>[['change_id'=>3,'operation'=>'delete','client_id'=>'b']],'next_after_id'=>3,'has_more'=>false];
applyClientsDeltaPage($delete,2);indexDenied(static fn()=>clientCatalogLookup('b'));
indexExpect(isset(clientCatalogLookup('a')['phone_index']['9990000001']),'Deleted collision did not release unique phone');
indexExpect(isset(clientCatalogLookup('a')['email_index']['same@example.test']),'Deleted collision did not release unique email');
// Failed full publication cannot be legitimized by a delta or an offline rebuild.
try{clientIdentityMutation(static fn()=>throw new RuntimeException('full crash'),null,'full');}catch(RuntimeException $e){}
indexUnavailable(static fn()=>clientCatalogLookup('a'));
try{applyClientsDeltaPage($delete,2);throw new LogicException('Partial full snapshot accepted');}
catch(RuntimeException $e){indexExpect(str_contains($e->getMessage(),'full refresh required'),'Wrong full recovery failure');}
$stream=clientsStreamingCacheCreate();clientsStreamingCacheAppend($stream,$rows);clientsStreamingCachePublish($stream);
indexExpect(clientCatalogLookup('a')['record']['id']==='a','Full refresh did not recover');
// Legacy snapshots with duplicate stable IDs must not resolve arbitrarily.
putenv('CALLTRACK_STORAGE_DIR='.$root.'/duplicates');
writeClientsCache([$rows[0],$rows[0],$rows[2]]);
indexDenied(static fn()=>clientCatalogLookup('a'));
indexExpect(clientCatalogLookup('c')['record']['id']==='c','Unrelated identity lost');
putenv('CALLTRACK_STORAGE_DIR='.$root);
// Exercise the offline command as a separate process against this same private snapshot.
foreach(['--check','--build','--check'] as $mode){
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/build_client_identity_index.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    indexExpect(proc_close($p)===0,'Offline command failed: '.$out.$err);
}
echo "client_identity_index: collision/reference/ACL/corruption/missing/version/concurrency/full/delta/recovery OK\n";
