<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client_directory.php';
require_once __DIR__ . '/test_clients.php';

const CLIENTS_REFRESH_TIMEZONE = 'Europe/Moscow';
const CLIENTS_DELTA_PAGE_SIZE = 500;

function clientsRefreshStorageDir(): string { $value=trim((string)(getenv('CALLTRACK_STORAGE_DIR') ?: '')); return $value!==''?rtrim($value,'/'):dirname(__DIR__).'/storage'; }
function ensureClientsRefreshStorage(): string { $dir=clientsRefreshStorageDir(); if (!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)) throw new RuntimeException('Не удалось создать служебный каталог CallTrack'); return $dir; }
function clientsRefreshLockFile(): string { return ensureClientsRefreshStorage().'/clients_cache_refresh.lock'; }
function clientsRefreshLogFile(): string { return ensureClientsRefreshStorage().'/logs/clients_cache_refresh.log'; }
function clientsCronMarkerFile(): string { $value=trim((string)(getenv('CALLTRACK_CLIENTS_CRON_MARKER') ?: '')); return $value!==''?$value:'/etc/calltrack/clients-cache-cron.installed'; }
function clientsRefreshNow(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone(CLIENTS_REFRESH_TIMEZONE)); }
function formatClientsRefreshTime(DateTimeImmutable $time): string { return $time->setTimezone(new DateTimeZone(CLIENTS_REFRESH_TIMEZONE))->format('d.m.Y H:i:s'); }
function clientsNextRefresh(?DateTimeImmutable $now=null): DateTimeImmutable { $now=($now??clientsRefreshNow())->setTimezone(new DateTimeZone(CLIENTS_REFRESH_TIMEZONE)); $next=$now->setTime(4,0); return $now<$next?$next:$next->modify('+1 day'); }
function clientsNextFullRefresh(?DateTimeImmutable $now=null): DateTimeImmutable { $now=($now??clientsRefreshNow())->setTimezone(new DateTimeZone(CLIENTS_REFRESH_TIMEZONE)); $next=$now->modify('next sunday')->setTime(3,0); if ((int)$now->format('w')===0 && $now<$now->setTime(3,0)) $next=$now->setTime(3,0); return $next; }
function appendClientsRefreshLog(string $message): void { $path=clientsRefreshLogFile(); $dir=dirname($path); if(!is_dir($dir))@mkdir($dir,0775,true); if(is_file($path)&&filesize($path)>5*1024*1024)@rename($path,$path.'.1'); @file_put_contents($path,'['.formatClientsRefreshTime(clientsRefreshNow()).'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX); }

function readClientsSyncState(): array { if(!is_file(clientsSyncStateFile())) return ['last_change_id'=>null]; $value=json_decode((string)file_get_contents(clientsSyncStateFile()),true); return is_array($value)?$value:['last_change_id'=>null]; }
function writeClientsSyncState(array $state): void { $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); $tmp=clientsSyncStateFile().'.new.'.getmypid(); if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,clientsSyncStateFile())){@unlink($tmp);throw new RuntimeException('Не удалось сохранить cursor Clients');} }

function clientsRefreshStatusPayload(array $status): array
{
    $state=readClientsSyncState(); $now=clientsRefreshNow(); $configured=is_file(clientsCronMarkerFile());
    $lastCron=strtotime((string)($status['last_cron_started_at']??''))?:false; $stale=$lastCron===false||$lastCron<$now->getTimestamp()-36*3600;
    return $status+$state+['cron_state'=>!$configured?'no_data':($stale?($lastCron===false?'configured':'overdue'):'working'),'cron_configured'=>$configured,
        'schedule'=>'Delta ежедневно в 04:00 МСК; full по воскресеньям в 03:00 МСК','timezone'=>CLIENTS_REFRESH_TIMEZONE,
        'next_run_at'=>formatClientsRefreshTime(clientsNextRefresh($now)),'next_full_run_at'=>formatClientsRefreshTime(clientsNextFullRefresh($now))];
}

function clientsChangesBaseUrl(): string
{
    $configured = trim((string)(getenv('CALLTRACK_CLIENTS_CHANGES_API_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/');

    // Delta является частью того же фактического /clients API. В production
    // полный endpoint часто настроен на локальный порт Clients, поэтому нельзя
    // обходить его жёстко заданным публичным доменом.
    $fullUrl = trim((string)(getenv('CALLTRACK_CLIENTS_PAGINATED_API_URL') ?: CLIENTS_PAGINATED_API_URL));
    $parts = parse_url($fullUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Некорректно настроен CLIENTS_PAGINATED_API_URL');
    }
    $authority = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $authority . rtrim((string)($parts['path'] ?? ''), '/') . '/changes';
}
function fetchClientsChangesJson(string $url): array
{
    $context=stream_context_create(['http'=>['timeout'=>30,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);
    $body=@file_get_contents($url,false,$context); $line=(string)($http_response_header[0]??'');
    if($body===false||!preg_match('/\s2\d\d\s/',$line)) throw new RuntimeException('Clients delta API недоступен'.($line!==''?': '.$line:''));
    $payload=json_decode($body,true); if(!is_array($payload)||($payload['status']??'')!=='success') {
        $message=(string)($payload['message']??'Некорректный ответ Clients delta API');
        if(stripos($message,'cursor')!==false) throw new RuntimeException('Требуется полное обновление кэша: '.$message);
        throw new RuntimeException($message);
    }
    return $payload;
}
function fetchClientsChangeState(): int { $payload=fetchClientsChangesJson(clientsChangesBaseUrl().'/state'); if(!isset($payload['last_change_id'])||!is_numeric($payload['last_change_id'])) throw new RuntimeException('Clients state API не вернул last_change_id'); return (int)$payload['last_change_id']; }
function fetchClientsChangesPage(int $afterId,int $limit=CLIENTS_DELTA_PAGE_SIZE): array { return fetchClientsChangesJson(clientsChangesBaseUrl().'?'.http_build_query(['after_id'=>$afterId,'limit'=>$limit])); }

function normalizeDeltaClient(array $row,string $clientId): array
{
    $name=clientValue($row,['name','Наименование','client_name','client']); $raw=clientRawValue($row,['phones','Телефоны','phone_numbers','phone']); $phones=[];
    foreach(is_array($raw)?$raw:[$raw] as $phone) foreach(splitClientPhones((string)$phone) as $normalized)$phones[$normalized]=$normalized;
    return ['id'=>$clientId,'name'=>$name,'phones'=>array_values($phones),'fields'=>filledClientFields($row)];
}
function clientsShardCodesForClient(PDO $pdo,string $clientId): array { $s=$pdo->prepare('SELECT normalized_phone FROM client_phones WHERE client_id=?');$s->execute([$clientId]);$codes=[];foreach($s as $r)$codes[substr(sha1((string)$r['normalized_phone']),0,2)]=true;return $codes; }
function rebuildClientsShard(PDO $pdo,string|int $code): void
{
    $code=str_pad((string)$code,2,'0',STR_PAD_LEFT);
    $rows=[]; $query=$pdo->prepare('SELECT p.normalized_phone,c.payload_json FROM client_phones p JOIN clients c ON c.client_id=p.client_id WHERE p.shard_code=? ORDER BY p.normalized_phone,c.client_id');$query->execute([$code]);
    foreach($query as $row){$phone=(string)$row['normalized_phone'];$client=json_decode((string)$row['payload_json'],true);if(is_array($client))$rows[$phone][]=['phone'=>'+7'.$phone,'name'=>(string)($client['name']??''),'fields'=>is_array($client['fields']??null)?$client['fields']:[]];}
    $target=clientsCacheShardsDirectory().'/'.$code.'.json';$tmp=$target.'.new.'.getmypid();
    if(!$rows){@unlink($target);return;} if(file_put_contents($tmp,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$target))throw new RuntimeException('Не удалось обновить shard Clients '.$code);
}

/** Применяет одну страницу атомарно в SQLite; cursor публикуется только после shard-файлов и COMMIT. */
function applyClientsDeltaPage(array $page,int $afterId,?callable $beforeCursorCommit=null): array
{
    $items=$page['items']??null;$next=$page['next_after_id']??null;if(!is_array($items)||!is_numeric($next)||!isset($page['has_more']))throw new RuntimeException('Некорректная страница Clients changes');
    $next=(int)$next;if(!$items&&$next!==$afterId)throw new RuntimeException('Требуется полное обновление кэша: пустая delta-страница с невозможным cursor');
    $pdo=clientsOpenSqlite();$pdo->beginTransaction();$upserts=0;$deletes=0;$codes=[];$previous=$afterId;
    try{
        foreach($items as $item){if(!is_array($item)||!isset($item['change_id'],$item['operation'],$item['client_id'])||(int)$item['change_id']<=$previous)throw new RuntimeException('Требуется полное обновление кэша: нарушен порядок change_id');$previous=(int)$item['change_id'];$id=(string)$item['client_id'];
            $codes+=clientsShardCodesForClient($pdo,$id);
            if($item['operation']==='upsert'){if(!is_array($item['client']??null))throw new RuntimeException('Upsert Clients не содержит client');clientsSqliteUpsert($pdo,normalizeDeltaClient($item['client'],$id),$id);$upserts++;}
            elseif($item['operation']==='delete'){$pdo->prepare('DELETE FROM clients WHERE client_id=?')->execute([$id]);$deletes++;}
            else throw new RuntimeException('Неизвестная операция Clients: '.(string)$item['operation']);
            $codes+=clientsShardCodesForClient($pdo,$id);
        }
        if($items&&$next!==$previous)throw new RuntimeException('Требуется полное обновление кэша: next_after_id не совпадает с последним change_id');
        foreach(array_keys($codes) as $code)rebuildClientsShard($pdo,$code);
        if($beforeCursorCommit)$beforeCursorCommit();
        $pdo->prepare("INSERT INTO sync_state(key,value) VALUES('last_change_id',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([(string)$next]);
        $pdo->commit();
        return ['changes'=>count($items),'upserts'=>$upserts,'deletes'=>$deletes,'cursor'=>$next];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function runClientsDeltaPages(int $cursor,?callable $fetchPage=null,?callable $beforeCursorCommit=null): array
{
    $fetchPage=$fetchPage??fn(int $after):array=>fetchClientsChangesPage($after);$total=['changes'=>0,'upserts'=>0,'deletes'=>0,'pages'=>0,'cursor'=>$cursor];
    do{$page=$fetchPage($cursor);$result=applyClientsDeltaPage($page,$cursor,$beforeCursorCommit);$total['pages']++;foreach(['changes','upserts','deletes'] as $key)$total[$key]+=$result[$key];$cursor=$result['cursor'];$total['cursor']=$cursor;
        // JSON cursor — межпроцессный источник истины. Он меняется только после
        // успешных SQLite COMMIT и публикации всех затронутых shard-файлов.
        $state=readClientsSyncState();$state['last_change_id']=$cursor;writeClientsSyncState($state);
        $more=(bool)$page['has_more'];if($more&&$result['changes']===0)throw new RuntimeException('Требуется полное обновление кэша: has_more у пустой страницы');}while($more);
    return $total;
}

function runClientsDeltaRefreshUnlocked(string $source,int $startCursor,?callable $fetchPage=null): array
{
    $started=clientsRefreshNow();$state=readClientsSyncState()+[];$state['last_delta_started_at']=$started->format(DATE_ATOM);$state['last_delta_status']='running';$state['last_error']=null;writeClientsSyncState($state);appendClientsRefreshLog('Начато delta обновление');appendClientsRefreshLog('Начальный cursor: '.$startCursor);
    try{$stats=runClientsDeltaPages($startCursor,$fetchPage);$finished=clientsRefreshNow();$state=array_merge($state,['last_change_id'=>$stats['cursor'],'last_delta_finished_at'=>$finished->format(DATE_ATOM),'last_delta_status'=>'success','changes_processed'=>$stats['changes'],'upserts'=>$stats['upserts'],'deletes'=>$stats['deletes'],'last_error'=>null]);writeClientsSyncState($state);
        appendClientsRefreshLog('Получено страниц: '.$stats['pages']);appendClientsRefreshLog('Изменений: '.$stats['changes']);appendClientsRefreshLog('Upsert: '.$stats['upserts']);appendClientsRefreshLog('Delete: '.$stats['deletes']);appendClientsRefreshLog('Новый cursor: '.$stats['cursor']);appendClientsRefreshLog('Peak memory: '.round(memory_get_peak_usage(true)/1048576,1).' MB');appendClientsRefreshLog('Время: '.round(microtime(true)-(float)$started->format('U.u'),3).' сек.');appendClientsRefreshLog('Delta кэш успешно обновлен');return $stats;
    }catch(Throwable $e){$state['last_delta_finished_at']=clientsRefreshNow()->format(DATE_ATOM);$state['last_delta_status']='error';$state['last_error']=$e->getMessage();writeClientsSyncState($state);appendClientsRefreshLog('ERROR delta: '.$e->getMessage());throw $e;}
}

function acquireClientsRefreshLock() { $lock=@fopen(clientsRefreshLockFile(),'c');if($lock===false)throw new RuntimeException('Не удалось открыть файл блокировки обновления кэша Clients');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('Обновление кэша Clients уже выполняется');}return $lock; }
function runClientsCacheRefresh(string $source,string $mode='full'): array
{
    $source=in_array($source,['cron','manual','background_test'],true)?$source:'manual';$mode=$mode==='delta'?'delta':'full';$lock=acquireClientsRefreshLock();$started=clientsRefreshNow();$previous=readClientsRefreshStatus();$running=['status'=>'running','success'=>false,'mode'=>$mode,'source'=>$source,'started_at'=>$started->format(DATE_ATOM),'finished_at'=>null,'error'=>null];if($source==='cron')$running['last_cron_started_at']=$started->format(DATE_ATOM);elseif(isset($previous['last_cron_started_at']))$running['last_cron_started_at']=$previous['last_cron_started_at'];writeClientsRefreshStatus($running);
    try{
        if($mode==='delta'){$state=readClientsSyncState();if(!is_numeric($state['last_change_id']??null)||!is_file(clientsSqliteFile()))throw new RuntimeException('Требуется полное обновление кэша');$stats=runClientsDeltaRefreshUnlocked($source,(int)$state['last_change_id']);$result=$running+[];$result=array_merge($result,['status'=>'success','success'=>true,'finished_at'=>clientsRefreshNow()->format(DATE_ATOM)],$stats);}
        else{$snapshot=fetchClientsChangeState();appendClientsRefreshLog('Начато полное обновление; snapshot cursor: '.$snapshot);$url=trim((string)(getenv('CALLTRACK_CLIENTS_PAGINATED_API_URL') ?: CLIENTS_PAGINATED_API_URL));$pageSize=max(100,min(2000,(int)(getenv('CALLTRACK_CLIENTS_REFRESH_PAGE_SIZE') ?: CLIENTS_REFRESH_PAGE_SIZE)));$stream=clientsStreamingCacheCreate();$count=0;$sourceCount=0;$page=1;$pages=0;$total=null;
            try{do{$batch=fetchClientsApiPage($url,$page,$pageSize);if($total!==null&&$batch['total']!==$total)throw new RuntimeException("Количество Clients изменилось во время обновления: {$total} → {$batch['total']}");$total=$batch['total'];$sourceCount+=$batch['source_count'];$count+=clientsStreamingCacheAppend($stream,$batch['clients']);$pages++;$more=(bool)$batch['has_more'];$page++;}while($more);if($total===null||$sourceCount!==$total)throw new RuntimeException("Пагинация Clients завершилась частично: обработано {$sourceCount} из {$total}");if($count===0)throw new RuntimeException('Clients API не вернул ни одной корректной записи');clientsStreamingCachePublish($stream);}catch(Throwable $e){clientsStreamingCacheAbort($stream);throw $e;}
            $state=readClientsSyncState();$state['last_change_id']=$snapshot;writeClientsSyncState($state);$catchup=runClientsDeltaRefreshUnlocked($source,$snapshot);$fullFinished=clientsRefreshNow()->format(DATE_ATOM);$state=readClientsSyncState();$state['last_full_finished_at']=$fullFinished;writeClientsSyncState($state);$result=array_merge($running,['status'=>'success','success'=>true,'finished_at'=>$fullFinished,'clients'=>$count,'processed_pages'=>$pages,'source_total'=>$total,'last_full_finished_at'=>$fullFinished,'catchup_changes'=>$catchup['changes']]);appendClientsRefreshLog('Полный кэш и delta catch-up успешно обновлены');}
        writeClientsRefreshStatus($result);return clientsRefreshStatusPayload($result);
    }catch(Throwable $e){$failed=$running;$failed['status']='error';$failed['finished_at']=clientsRefreshNow()->format(DATE_ATOM);$failed['error']=$e->getMessage();writeClientsRefreshStatus($failed);appendClientsRefreshLog('ERROR: '.$e->getMessage());throw $e;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function clientsRefreshIsLocked(): bool { try{$lock=acquireClientsRefreshLock();flock($lock,LOCK_UN);fclose($lock);return false;}catch(RuntimeException $e){if($e->getMessage()==='Обновление кэша Clients уже выполняется')return true;throw $e;} }
function startClientsCacheRefreshInBackground(string $mode='delta'): array
{
    $mode=$mode==='full'?'full':'delta';if(!function_exists('exec'))throw new RuntimeException('Фоновый запуск недоступен: функция PHP exec отключена');if(clientsRefreshIsLocked())throw new RuntimeException('Обновление кэша Clients уже выполняется');$php=trim((string)(getenv('CALLTRACK_PHP_CLI') ?: '/usr/bin/php'));$script=__DIR__.'/refresh_clients_cache.php';if(!is_executable($php)||!is_file($script))throw new RuntimeException('Не найден PHP CLI или скрипт обновления Clients');$starting=['status'=>'starting','success'=>false,'mode'=>$mode,'source'=>'manual','started_at'=>clientsRefreshNow()->format(DATE_ATOM),'finished_at'=>null,'error'=>null];writeClientsRefreshStatus($starting);$command=sprintf('nohup %s %s --source=manual --mode=%s </dev/null >/dev/null 2>&1 & echo $!',escapeshellarg($php),escapeshellarg($script),escapeshellarg($mode));exec($command,$output,$code);$pid=ctype_digit(trim((string)($output[0]??'')))?(int)$output[0]:0;if($code!==0||$pid<=0)throw new RuntimeException('Не удалось создать фоновый процесс обновления кэша Clients');$starting['pid']=$pid;return clientsRefreshStatusPayload($starting);
}
