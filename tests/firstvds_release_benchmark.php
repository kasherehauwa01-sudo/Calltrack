<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_catalog.php';
require_once __DIR__.'/../api/clients_cache_refresh.php';
if(isset($argv[1])){
    $id='bench-'.($argv[2]??'82499');$start=microtime(true);
    if($argv[1]==='before'){
        $pass=0;$rows=static function()use(&$pass,$id){return ++$pass===1?clientCatalogTargetRows($id):salesBuyerDirectoryRows();};
        $lookup=clientCatalogLookup($id,$rows);
    }else $lookup=clientCatalogLookup($id);
    if($lookup['record']['id']!==$id||!$lookup['name_unique']||count($lookup['phone_index'])!==1||count($lookup['email_index'])!==1)throw new RuntimeException('Incorrect benchmark identity');
    echo json_encode(['seconds'=>microtime(true)-$start,'peak_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);exit;
}
putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
putenv('CALLTRACK_STORAGE_DIR='.sys_get_temp_dir().'/identity-benchmark-'.bin2hex(random_bytes(8)));
$start=microtime(true);$stream=clientsStreamingCacheCreate();$count=165000;
for($offset=0;$offset<$count;$offset+=500){
    $batch=[];
    for($i=$offset;$i<min($count,$offset+500);$i++)$batch[]=[
        'id'=>'bench-'.$i,'name'=>'Synthetic client '.$i,
        'phones'=>['+79'.str_pad((string)$i,9,'0',STR_PAD_LEFT)],
        'fields'=>['email'=>'client'.$i.'@example.test','Менеджер'=>'Synthetic manager','extra'=>str_repeat('x',150)]
    ];
    clientsStreamingCacheAppend($stream,$batch);
}
unset($batch);clientsStreamingCachePublish($stream);
$build=microtime(true)-$start;
$buildPeak=memory_get_peak_usage(true);
$bytesBefore=0;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(clientsCacheDirectory(),FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$bytesBefore+=$file->getSize();
$generationBefore=json_decode(file_get_contents(clientIdentityDirectory().'/current.json'),true)['generation'];
$startDelta=microtime(true);$blocked=0;
$delta=runClientsDeltaPages(0,static function(int $after):array{
    $n=$after+1;
    return ['status'=>'success','items'=>[['change_id'=>$n,'operation'=>'upsert','client_id'=>'bench-0','client'=>[
        'id'=>'bench-0','name'=>'Synthetic client 0','phones'=>['+79000000000'],'email'=>'client0@example.test','Менеджер'=>'Synthetic manager'
    ]]],'next_after_id'=>$n,'has_more'=>$n<3];
},static function()use(&$blocked){
    try{clientCatalogLookup('bench-1');throw new LogicException('Read accepted during publication');}
    catch(ClientIdentityIndexUnavailable $e){$blocked++;}
});
$deltaSeconds=microtime(true)-$startDelta;
if($blocked!==3||$delta['pages']!==3)throw new RuntimeException('Series/concurrency scenario failed');
$generationAfter=json_decode(file_get_contents(clientIdentityDirectory().'/current.json'),true)['generation'];
if($generationBefore===$generationAfter||count(glob(clientIdentityDirectory().'/gen-*'))!==1)throw new RuntimeException('Generation publication/cleanup failed');
function measureIdentity(string $mode,int $id):array{
    $p=proc_open([PHP_BINARY,'-d','memory_limit=128M',__FILE__,$mode,(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Cannot spawn benchmark');
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0)throw new RuntimeException($output.$error);
    return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
$before=measureIdentity('before',82499);$after=[];
for($i=0;$i<20;$i++)$after[]=measureIdentity('after',($i*7919)%$count);
$times=array_column($after,'seconds');sort($times);
$indexBytes=0;foreach(glob(clientIdentityDirectory().'/gen-*/*.json')?:[] as $file)$indexBytes+=filesize($file);
$result=['php'=>PHP_VERSION,'pdo_sqlite_loaded'=>extension_loaded('pdo_sqlite'),'clients'=>$count,
    'source_bytes'=>filesize(clientsCacheFile()),'index_bytes'=>$indexBytes,'build_seconds'=>$build,'build_peak_bytes'=>$buildPeak,'published_cache_bytes'=>$bytesBefore,'three_delta_pages_seconds'=>$deltaSeconds,'blocked_reads'=>$blocked,
    'before'=>$before,'after_cold_first'=>$after[0],'after_p50_seconds'=>$times[9],'after_p95_seconds'=>$times[18],
    'after_max_seconds'=>max($times),'after_peak_bytes'=>max(array_column($after,'peak_bytes')),
    'scope'=>'Directory ID resolution + global identity uniqueness; separate PHP processes, warm OS page cache; excludes remote purchase API/DB'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
if(max($times)>=2||max(array_column($after,'peak_bytes'))>=32*1024*1024)throw new RuntimeException('Indexed directory latency/memory regression');
