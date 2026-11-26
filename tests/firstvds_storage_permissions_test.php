<?php
declare(strict_types=1);
require_once __DIR__.'/../api/clients_cache_refresh.php';
if(($argv[1]??'')==='child'){
    $root=$argv[2];if(!str_starts_with($root,'/tmp/firstvds-permissions-'))throw new RuntimeException('Test path only');
    putenv('CALLTRACK_STORAGE_DIR='.$root.'/storage');
    if(is_writable($root.'/storage'))throw new RuntimeException('Fixture storage should not be writable');
    if((readClientsRefreshStatus()['status']??'')!=='legacy')throw new RuntimeException('Legacy status lost');
    $lock=acquireClientsRefreshLock();
    writeClientsRefreshStatus(['status'=>'new']);
    if((readClientsRefreshStatus()['status']??'')!=='new')throw new RuntimeException('Writable cache status failed');
    if(json_decode(file_get_contents(clientsLegacyRefreshStatusFile()),true)['status']!=='legacy')throw new RuntimeException('Legacy status modified');
    flock($lock,LOCK_UN);fclose($lock);
    echo "FirstVDS www-data/root-owned storage: lock, legacy status and atomic cache status OK\n";exit;
}
$root='/tmp/firstvds-permissions-'.bin2hex(random_bytes(8));
mkdir($root.'/storage/cache/clients',0775,true);
$uid=(int)trim(shell_exec('id -u www-data'));$gid=(int)trim(shell_exec('id -g www-data'));
foreach([$root,$root.'/storage',$root.'/storage/cache'] as $p)chmod($p,0755);
chown($root.'/storage/cache/clients',$uid);chgrp($root.'/storage/cache/clients',$gid);
file_put_contents($root.'/storage/clients_cache_refresh.status.json','{"status":"legacy"}');
file_put_contents($root.'/storage/clients_cache_refresh.lock','');
chgrp($root.'/storage/clients_cache_refresh.lock',$gid);chmod($root.'/storage/clients_cache_refresh.lock',0660);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' child '.escapeshellarg($root);
$p=proc_open(['su','-s','/bin/sh','www-data','-c',$command],[1=>STDOUT,2=>STDERR],$pipes);
if(proc_close($p)!==0)throw new RuntimeException('FirstVDS permissions regression');
