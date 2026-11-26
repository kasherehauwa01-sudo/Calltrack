<?php
declare(strict_types=1);
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'||getenv('CALLTRACK_STORAGE_DIR')!=='/tmp/firstvds-http-storage')throw new RuntimeException('Disposable local fixture only');
require_once __DIR__.'/../../api/client_catalog.php';
require_once __DIR__.'/../../api/clients_cache_refresh.php';
$stream=clientsStreamingCacheCreate();
$batch=[];
foreach(clientIdentityRows() as $row){$batch[]=$row;if(count($batch)===500){clientsStreamingCacheAppend($stream,$batch);$batch=[];}}
if($batch)clientsStreamingCacheAppend($stream,$batch);
for($offset=0;$offset<165000;$offset+=500){
    $batch=[];
    for($n=$offset;$n<$offset+500;$n++)$batch[]=['id'=>'http-synthetic-'.$n,'name'=>'HTTP synthetic '.$n,'phones'=>['+79'.str_pad((string)($n+1000000),9,'0',STR_PAD_LEFT)],'fields'=>['email'=>'http'.$n.'@example.test','Менеджер'=>'One','extra'=>str_repeat('x',150)]];
    clientsStreamingCacheAppend($stream,$batch);
}
clientsStreamingCachePublish($stream);
echo "Large local HTTP directory published: existing fixtures plus 165000 synthetic clients; peak=".memory_get_peak_usage(true)."\n";
