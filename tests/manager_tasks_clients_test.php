<?php
declare(strict_types=1);
require __DIR__.'/../api/client_catalog_json_stream.php';
require __DIR__.'/../api/client_catalog_cache.php';
require __DIR__.'/../api/manager_tasks_clients.php';
$dir=sys_get_temp_dir().'/task-names-'.bin2hex(random_bytes(6));mkdir($dir);putenv('CALLTRACK_STORAGE_DIR='.$dir);
function clientsSqliteAvailable():bool{return false;}
function clientsSqliteFile():string{return $GLOBALS['dir'].'/clients.sqlite';}
function clientsCacheFile():string{return $GLOBALS['dir'].'/clients.json';}
function clientsRecordsReadyFile():string{return $GLOBALS['dir'].'/records.ready';}
function clientsSyncStateFile():string{return $GLOBALS['dir'].'/state';}
function clientsRecordShardFile(string $id):string{return $GLOBALS['dir'].'/'.substr(sha1($id),0,2).'.json';}
function analyticsDirectoryRecords(array $ids):array{throw new RuntimeException('Unexpected full catalogue scan');}
try {
 file_put_contents(clientsRecordsReadyFile(),'ready');
 $id='client-1';$file=clientsRecordShardFile($id);
 file_put_contents($file,json_encode([$id=>['id'=>$id,'name'=>'Радуга']]));
 file_put_contents($dir.'/unrelated.json','invalid JSON must never be read');
 $a=taskViewClients([$id]);if($a[$id]['name']!=='Радуга')throw new RuntimeException('Name lookup');
 file_put_contents($file,'invalid');
 if(taskViewClients([$id])!==$a)throw new RuntimeException('Warm cache not used');
 file_put_contents(clientsSyncStateFile(),'new revision');
 file_put_contents($file,json_encode([$id=>['id'=>$id,'name'=>'Новое имя']]));
 if(taskViewClients([$id])[$id]['name']!=='Новое имя')throw new RuntimeException('Revision not invalidated');
 if(taskViewClients([])!==[])throw new RuntimeException('Empty page');
 echo "PASS: targeted shard reads, warm name cache, directory revision invalidation\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
