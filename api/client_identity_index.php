<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog_json_stream.php';

/** Immutable, checksummed directory generations. No SQLite requirement or HTTP rebuild. */
class ClientIdentityIndexUnavailable extends RuntimeException {}
const CLIENT_IDENTITY_INDEX_VERSION = 1;
const CLIENT_IDENTITY_SHARD_LIMIT = 8388608;

function clientIdentityDirectory(): string { return clientsCacheDirectory().'/identity-v1'; }
function clientIdentityName(string $name): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)??'')); }
function clientIdentityCode(string $key): string { return substr(hash('sha256',$key),0,2); }
function clientIdentityFail(): never { throw new ClientIdentityIndexUnavailable('Индекс клиентов недоступен. Повторите запрос после обновления справочника.'); }

function clientIdentityLock(bool $write) {
    $path=clientsCacheDirectory().'/identity.lock';
    $lock=@fopen($path,$write?'c':'r');
    if(!$lock||!flock($lock,($write?LOCK_EX:LOCK_SH)|LOCK_NB)){if(is_resource($lock))fclose($lock);clientIdentityFail();}
    return $lock;
}

/** Cheap generation fence: stat only, never hash/decode the large directory in HTTP. */
function clientIdentitySource(): string {
    $base=clientsCacheDirectory();
    $files=[clientsCacheFile(),clientsRecordsReadyFile(),clientsSqliteFile(),clientsSqliteFile().'-wal'];
    foreach(glob(clientsRecordsDirectory().'/*.json')?:[] as $file)$files[]=$file;
    sort($files,SORT_STRING);$parts=[];
    foreach($files as $file){clearstatcache(true,$file);$s=@stat($file);
        $parts[]=[substr($file,strlen($base)),$s?[$s['dev'],$s['ino'],$s['size'],$s['mtime'],$s['ctime']]:null];}
    return hash('sha256',json_encode([clientsSqliteAvailable(),$parts],JSON_THROW_ON_ERROR));
}

function clientIdentityReadJson(string $file,int $limit): array {
    $s=@stat($file);if(!$s||$s['size']>$limit)clientIdentityFail();
    $raw=@file_get_contents($file,false,null,0,$limit+1);
    if($raw===false||strlen($raw)>$limit)clientIdentityFail();
    try{$value=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){clientIdentityFail();}
    if(!is_array($value))clientIdentityFail();return $value;
}

/** All reads and their uniqueness/ACL input come from one shared-locked generation. */
function clientIdentitySnapshot(callable $read): mixed {
    $lock=clientIdentityLock(false);
    try{
        if(is_file(clientIdentityDirectory().'/incomplete.json'))clientIdentityFail();
        $meta=clientIdentityReadJson(clientIdentityDirectory().'/current.json',262144);
        if(($meta['version']??null)!==CLIENT_IDENTITY_INDEX_VERSION
            ||!preg_match('/^gen-[a-f0-9]{32}$/D',(string)($meta['generation']??''))
            ||($meta['source']??null)!==clientIdentitySource()
            ||!is_array($meta['files']??null)||count($meta['files'])!==512)clientIdentityFail();
        $value=$read($meta);
        if($meta['source']!==clientIdentitySource())clientIdentityFail();
        return $value;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function clientIdentityBucket(array $meta,string $kind,string $key): array {
    $name=$kind.'-'.clientIdentityCode($key).'.json';$info=$meta['files'][$name]??null;
    if(!is_array($info)||!is_int($info['bytes']??null)||$info['bytes']<2||$info['bytes']>CLIENT_IDENTITY_SHARD_LIMIT
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($info['sha256']??'')))clientIdentityFail();
    $file=clientIdentityDirectory().'/'.$meta['generation'].'/'.$name;
    $raw=@file_get_contents($file,false,null,0,$info['bytes']+1);
    if($raw===false||strlen($raw)!==$info['bytes']||!hash_equals($info['sha256'],hash('sha256',$raw)))clientIdentityFail();
    try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){clientIdentityFail();}
    if(!is_array($data))clientIdentityFail();return $data;
}

function clientIdentityOwner(array $meta,string $type,string $value): ?string {
    $key=$type.':'.$value;$bucket=clientIdentityBucket($meta,'keys',$key);
    // A missing address of a stored record is corruption, not evidence of uniqueness.
    if(!array_key_exists($key,$bucket))clientIdentityFail();
    $owner=$bucket[$key];if($owner!==null&&!is_string($owner))clientIdentityFail();
    return $owner;
}

function clientIdentityRecord(array $meta,string $id): array {
    $bucket=clientIdentityBucket($meta,'records',$id);$record=$bucket[$id]??null;
    if($record===null)throw new ClientCatalogAccessDenied('Клиент недоступен');
    if(!is_array($record)||($record['id']??null)!==$id||!is_string($record['name']??null)
        ||!is_array($record['phones']??null)||!is_array($record['emails']??null)
        ||!is_string($record['manager']??null)||!is_bool($record['manager_conflict']??null))clientIdentityFail();
    // Derived display fields are reconstructed rather than duplicated on disk.
    $record['manager_key']=managerNameKey($record['manager']);
    $record['fields']=['id'=>$id,'Телефоны'=>$record['phones'],'email'=>$record['emails'],'Менеджер'=>$record['manager']];
    return $record;
}

function clientIdentityLookupInSnapshot(array $meta,string $id): array {
    $record=clientIdentityRecord($meta,$id);$phones=[];$emails=[];
    foreach($record['phones'] as $phone){if(!is_string($phone))clientIdentityFail();if(clientIdentityOwner($meta,'phone',$phone)===$id)$phones[$phone]=$id;}
    foreach($record['emails'] as $email){if(!is_string($email))clientIdentityFail();if(clientIdentityOwner($meta,'email',$email)===$id)$emails[$email]=$id;}
    return ['record'=>$record,'phone_index'=>$phones,'email_index'=>$emails,
        'name_unique'=>clientIdentityOwner($meta,'name',clientIdentityName($record['name']))===$id];
}

function clientIdentityRemoveTree(string $directory): void {
    if(!is_dir($directory))return;
    foreach(glob($directory.'/*')?:[] as $file){if(is_dir($file))throw new RuntimeException('Unexpected nested identity directory');if(!@unlink($file))throw new RuntimeException('Cannot remove identity staging file');}
    if(!@rmdir($directory))throw new RuntimeException('Cannot remove identity staging directory');
}


/** Builder-only source. Optional SQLite is read-only; never opens application databases. */
function clientIdentityRows(): Generator {
    if(clientsSqliteAvailable()&&is_file(clientsSqliteFile())){
        $db=new PDO('sqlite:'.clientsSqliteFile(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        foreach($db->query('SELECT payload_json FROM clients') as $row){
            $record=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($record))throw new RuntimeException('Invalid directory record');
            yield $record;
        }
        return;
    }
    $files=glob(clientsRecordsDirectory().'/*.json')?:[];
    if(is_file(clientsRecordsReadyFile())||$files){
        foreach($files as $file)yield from (new ClientCatalogJsonStream($file))->rows();
        return;
    }
    if(!is_file(clientsCacheFile()))throw new RuntimeException('Directory snapshot is missing');
    yield from (new ClientCatalogJsonStream(clientsCacheFile()))->rows();
}

/** Off-request build: partition on disk, retain one compact bucket, not all clients. */
function clientIdentityBuild(?iterable $rows=null): void {
    require_once __DIR__.'/client_catalog.php';
    $directory=clientIdentityDirectory();if(!is_dir($directory)&&!mkdir($directory,0775,true))throw new RuntimeException('Cannot create identity index');
    // Called under the publication lock: remove only abandoned derived generations.
    $keep=null;
    if(is_file($directory.'/current.json')){
        try{$keep=clientIdentityReadJson($directory.'/current.json',262144)['generation']??null;}catch(Throwable $ignored){}
    }
    foreach(glob($directory.'/gen-*')?:[] as $old)
        if(basename($old)!==$keep&&preg_match('/^gen-[a-f0-9]{32}$/D',basename($old)))clientIdentityRemoveTree($old);
    foreach(glob($directory.'/current.new.*')?:[] as $old)if(is_file($old))unlink($old);
    clientIdentitySpaceGuard($directory);
    $generation='gen-'.bin2hex(random_bytes(16));$stage=$directory.'/'.$generation;
    if(!mkdir($stage,0775))throw new RuntimeException('Cannot stage identity index');
    $source=clientIdentitySource();$handles=[];$files=[];$count=0;$published=false;
    try{
        $rows??=clientIdentityRows();
        foreach($rows as $raw){
            $records=clientCatalogRecords([$raw]);if(!$records)continue;$record=reset($records);$id=$record['id'];$count++;
            if($count%1000===0)clientIdentitySpaceGuard($directory);
            $stored=$record;unset($stored['fields'],$stored['manager_key']);
            $entries=[['records',$id,$stored],['keys','name:'.clientIdentityName($record['name']),$id],
                ['keys','buyer_name:'.mb_strtolower(trim($record['name'])),$id]];
            foreach($record['phones'] as $value)$entries[]=['keys','phone:'.$value,$id];
            foreach($record['emails'] as $value)$entries[]=['keys','email:'.$value,$id];
            foreach($entries as [$kind,$key,$value]){
                $name=$kind.'-'.clientIdentityCode($key);$path=$stage.'/'.$name.'.ndjson';
                if(!isset($handles[$name])){$handles[$name]=fopen($path,'xb');if(!$handles[$name])throw new RuntimeException('Cannot open identity partition');}
                $line=json_encode([$key,$value],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
                if(fwrite($handles[$name],$line)!==strlen($line))throw new RuntimeException('Short identity write');
            }
        }
        foreach($handles as $handle)fclose($handle);$handles=[];
        foreach(['records','keys'] as $kind)for($i=0;$i<256;$i++){
            $name=$kind.'-'.sprintf('%02x',$i);$path=$stage.'/'.$name.'.ndjson';$data=[];
            if(is_file($path)){
                $input=fopen($path,'rb');
                try{while(($line=fgets($input))!==false){
                    [$key,$value]=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                    if($kind==='records'&&array_key_exists($key,$data)){$data[$key]=null;continue;}
                    if(!array_key_exists($key,$data))$data[$key]=$value;
                    elseif($data[$key]!==$value)$data[$key]=null;
                }}finally{fclose($input);}
                unlink($path);
            }
            $json=json_encode((object)$data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(strlen($json)>CLIENT_IDENTITY_SHARD_LIMIT)throw new RuntimeException('Identity partition limit exceeded');
            $file=$name.'.json';if(file_put_contents($stage.'/'.$file,$json)!==strlen($json))throw new RuntimeException('Short identity partition write');
            $files[$file]=['bytes'=>strlen($json),'sha256'=>hash('sha256',$json)];unset($data,$json);
        }
        if(clientIdentitySource()!==$source)throw new RuntimeException('Directory changed during identity build');
        $meta=['version'=>CLIENT_IDENTITY_INDEX_VERSION,'generation'=>$generation,'source'=>$source,'records'=>$count,'files'=>$files];
        $json=json_encode($meta,JSON_THROW_ON_ERROR);$temporary=$directory.'/current.new.'.bin2hex(random_bytes(8));
        if(file_put_contents($temporary,$json)!==strlen($json)||!rename($temporary,$directory.'/current.json'))throw new RuntimeException('Cannot publish identity index');
        $published=true;
        // Shared/exclusive lock ensures no reader is using an obsolete generation here.
        foreach(glob($directory.'/gen-*')?:[] as $old)if($old!==$stage&&preg_match('/^gen-[a-f0-9]{32}$/D',basename($old)))clientIdentityRemoveTree($old);
    }catch(Throwable $e){
        foreach($handles as $handle)if(is_resource($handle))fclose($handle);
        if(!$published)clientIdentityRemoveTree($stage);throw $e;
    }
}

/** The invalid marker survives a failed/partial legacy publication or worker crash. */
function clientIdentityMutation(callable $write,?callable $rows=null,string $kind='delta',?int $cursor=null): mixed {
    static $active=false;
    if($active)return $write();
    $lock=clientIdentityLock(true);$active=true;
    try{
        $directory=clientIdentityDirectory();if(!is_dir($directory)&&!mkdir($directory,0775,true))throw new RuntimeException('Cannot create identity directory');
        $dirty=$directory.'/incomplete.json';
        if($kind!=='full'&&is_file($dirty)){
            $previous=clientIdentityReadJson($dirty,4096);
            if(($previous['kind']??null)!=='delta')throw new RuntimeException('Incomplete full snapshot; full refresh required');
            if($cursor===null||!in_array($cursor,$previous['allowed_after']??[],true))throw new RuntimeException('Incomplete delta: exact replay or full refresh required');
        }
        if($kind==='full'||!is_file($dirty))clientIdentityWriteMarker(['kind'=>$kind,'allowed_after'=>$cursor===null?[]:[$cursor]]);
        $marker=$directory.'/current.json';
        if(is_file($marker)&&!unlink($marker))throw new RuntimeException('Cannot invalidate identity generation');
        $value=$write();
        clientIdentityBuild($rows?$rows():null);
        if(!unlink($dirty))throw new RuntimeException('Cannot complete directory publication');
        return $value;
    }finally{$active=false;flock($lock,LOCK_UN);fclose($lock);}
}

/** Atomic recovery journal: never certify a different page after a partial write. */
function clientIdentityWriteMarker(array $state): void {
    $file=clientIdentityDirectory().'/incomplete.json';
    $tmp=$file.'.new.'.bin2hex(random_bytes(8));
    try{
        $json=json_encode($state,JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$file))throw new RuntimeException('Cannot persist identity recovery journal');
    }finally{if(is_file($tmp))unlink($tmp);}
}

function clientIdentityDeltaPage(array $page,int $after,callable $apply): array {
    $state=clientIdentityReadJson(clientIdentityDirectory().'/incomplete.json',4096);
    $hash=hash('sha256',json_encode($page,JSON_THROW_ON_ERROR));
    if(($state['kind']??null)!=='delta'||!in_array($after,$state['allowed_after']??[],true))
        throw new RuntimeException('Incomplete delta: exact replay or full refresh required');
    if(isset($state['page_sha256'])&&!($state['completed']??false)&&!hash_equals($state['page_sha256'],$hash))
        throw new RuntimeException('Interrupted delta page changed; full refresh required');
    clientIdentityWriteMarker(['kind'=>'delta','allowed_after'=>[$after],'page_sha256'=>$hash,'completed'=>false]);
    $result=$apply();
    clientIdentityWriteMarker(['kind'=>'delta','allowed_after'=>array_values(array_unique([$after,(int)$result['cursor']])),'page_sha256'=>$hash,'completed'=>true]);
    return $result;
}

/** Bound derived staging growth; never remove canonical clients/records/shards. */
function clientIdentitySpaceGuard(string $directory): void {
    $bytes=0;
    foreach(glob($directory.'/*')?:[] as $entry){
        foreach(is_dir($entry)?(glob($entry.'/*')?:[]):[$entry] as $file){
            clearstatcache(true,$file);if(is_file($file))$bytes+=(int)filesize($file);
        }
    }
    $free=disk_free_space($directory);
    if($bytes>536870912||$free===false||$free<268435456)throw new RuntimeException('Insufficient bounded staging space; preserve source and inspect abandoned temporary files');
}
