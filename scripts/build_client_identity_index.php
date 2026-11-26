<?php
declare(strict_types=1);
/** Offline derived index only. Never requests Clients/Sales Journal or connects to application databases. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../api/clients_cache_refresh.php';
require_once __DIR__.'/../api/client_catalog.php';
try{
    $mode=$argv[1]??'--check';
    if(!in_array($mode,['--check','--build'],true)||count($argv)>2)throw new InvalidArgumentException('Use --check or --build');
    if($mode==='--check'){
        $meta=clientIdentitySnapshot(static function($m){
            foreach($m['files'] as $name=>$info){
                if(!preg_match('/^(records|keys)-[a-f0-9]{2}\\.json$/D',$name))clientIdentityFail();
                $file=clientIdentityDirectory().'/'.$m['generation'].'/'.$name;
                if(!is_file($file)||filesize($file)!==($info['bytes']??null)||!hash_equals((string)($info['sha256']??''),hash_file('sha256',$file)))clientIdentityFail();
            }
            return $m;
        });
        echo json_encode(['status'=>'ready','version'=>$meta['version'],'records'=>$meta['records']],JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    // Also excludes legacy cron/full/delta jobs during an initial offline build.
    $refresh=acquireClientsRefreshLock();$lock=null;
    try{
        $lock=clientIdentityLock(true);
        if(is_file(clientIdentityDirectory().'/incomplete.json'))throw new RuntimeException('Interrupted directory publication; complete a full refresh before rebuilding');
        if(!is_file(clientsRecordsReadyFile())&&!is_file(clientsCacheFile())&&!is_file(clientsSqliteFile()))throw new RuntimeException('Directory snapshot is missing');
        $start=microtime(true);clientIdentityBuild();
        echo json_encode(['status'=>'built','seconds'=>round(microtime(true)-$start,3),'peak_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR)."\n";
    }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}flock($refresh,LOCK_UN);fclose($refresh);}
}catch(Throwable $e){fwrite(STDERR,'Identity index: '.$e->getMessage()."\n");exit(1);}
