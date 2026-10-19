<?php
declare(strict_types=1);

/** Derived, short-lived read caches only; directory import remains the source of truth. */
function clientCatalogRevision(): string
{
    $files=[clientsSqliteFile(),clientsSqliteFile().'-wal',clientsCacheFile(),clientsRecordsReadyFile(),clientsSyncStateFile()];$parts=[];
    foreach($files as $file){clearstatcache(true,$file);$stat=@stat($file);$parts[]=[$file,$stat?[$stat['ino'],$stat['mtime'],$stat['size']]:null];}
    return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
}

function clientCatalogCachedArray(string $key,callable $build,int $ttl=60,?string $directory=null): array
{
    $storage=trim((string)(getenv('CALLTRACK_STORAGE_DIR')?:''));
    $directory??=($storage!==''?rtrim($storage,'/'):dirname(__DIR__).'/storage').'/cache/client-catalog';
    if(!is_dir($directory)&&!@mkdir($directory,0775,true)&&!is_dir($directory))return $build();
    $file=$directory.'/'.hash('sha256',$key).'.json';
    $read=static function()use($file):?array{
        $raw=@file_get_contents($file);if($raw===false)return null;
        $value=json_decode($raw,true);
        return is_array($value)&&($value['version']??null)===1&&($value['expires_at']??0)>time()&&is_array($value['data']??null)?$value['data']:null;
    };
    $cached=$read();if($cached!==null)return $cached;
    $lock=@fopen($file.'.lock','c');
    if($lock===false)return $build();
    try{
        if(!flock($lock,LOCK_EX))return $build();
        $cached=$read();if($cached!==null)return $cached;
        $data=$build();
        $json=json_encode(['version'=>1,'expires_at'=>time()+$ttl,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $temporary=$file.'.'.getmypid().'.tmp';
        if(@file_put_contents($temporary,$json,LOCK_EX)===false||!@rename($temporary,$file))@unlink($temporary);
        foreach(glob($directory.'/*.json')?:[] as $old){
            if(preg_match('/^[a-f0-9]{64}\.json$/',basename($old))&&(@filemtime($old)?:time())<time()-86400)@unlink($old);
        }
        return $data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
