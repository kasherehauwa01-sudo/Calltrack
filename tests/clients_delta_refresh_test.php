<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/clients_cache_refresh.php';
function deltaExpect(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$root='/var/tmp/calltrack_delta_test_'.getmypid();mkdir($root,0777,true);putenv('CALLTRACK_STORAGE_DIR='.$root.'/storage');
$pdo=clientsOpenSqlite();
$previousPaginated=getenv('CALLTRACK_CLIENTS_PAGINATED_API_URL');
putenv('CALLTRACK_CLIENTS_CHANGES_API_URL');
putenv('CALLTRACK_CLIENTS_PAGINATED_API_URL=http://127.0.0.1:8015/api/clients?ignored=1');
deltaExpect(clientsChangesBaseUrl()==='http://127.0.0.1:8015/api/clients/changes','Delta URL должен использовать тот же Clients endpoint, что и full');
if($previousPaginated===false)putenv('CALLTRACK_CLIENTS_PAGINATED_API_URL');else putenv('CALLTRACK_CLIENTS_PAGINATED_API_URL='.$previousPaginated);
deltaExpect(clientsRefreshFailureMessage(['status'=>'error','error'=>'Причина сбоя'])==='Причина сбоя','Тест API должен показывать поле error статуса refresh');
$page=fn(array $items,int $after,int $next,bool $more=false):array=>['status'=>'success','items'=>$items,'next_after_id'=>$next,'has_more'=>$more];
$upsert=fn(int $change,string $id,string $name,array $phones):array=>['change_id'=>$change,'changed_at'=>'2026-08-13T12:00:00','operation'=>'upsert','client_id'=>$id,'client'=>['id'=>$id,'name'=>$name,'phones'=>$phones,'company'=>$name]];
$delete=fn(int $change,string $id):array=>['change_id'=>$change,'changed_at'=>'2026-08-13T12:00:00','operation'=>'delete','client_id'=>$id];

$result=applyClientsDeltaPage($page([],0,0),0);deltaExpect($result['changes']===0&&$result['cursor']===0,'Delta без изменений повреждена');
applyClientsDeltaPage($page([$upsert(1,'10','Первый',['+79990000001'])],0,1),0);
deltaExpect(readClientMatchesCache('9990000001')[0]['name']==='Первый','Новый клиент не найден');
applyClientsDeltaPage($page([$upsert(2,'10','Новое имя',['+79990000002'])],1,2),1);
deltaExpect(readClientMatchesCache('9990000001')===[],'Старый телефон остался после upsert');deltaExpect(readClientMatchesCache('9990000002')[0]['name']==='Новое имя','Имя или новый телефон не обновлены');
applyClientsDeltaPage($page([$upsert(3,'10','Несколько',['+79990000002','+79990000003'])],2,3),2);deltaExpect(count(readClientMatchesCache('9990000003'))===1,'Несколько телефонов не сохранены');
applyClientsDeltaPage($page([$upsert(3,'10','Несколько',['+79990000002','+79990000003'])],2,3),2);deltaExpect(count(readClientMatchesCache('9990000003'))===1,'Повторный upsert не идемпотентен');
applyClientsDeltaPage($page([$delete(4,'10')],3,4),3);applyClientsDeltaPage($page([$delete(4,'10')],3,4),3);deltaExpect(readClientMatchesCache('9990000002')===[],'Delete или повторный delete не сработал');

$pages=[5=>$page([$upsert(6,'20','A',['+79990000004'])],5,6,true),6=>$page([$upsert(7,'21','B',['+79990000005'])],6,7,false)];$stats=runClientsDeltaPages(5,fn(int $after)=>$pages[$after]);deltaExpect($stats['pages']===2&&$stats['cursor']===7,'Несколько страниц обработаны неверно');
$before=(int)$pdo->query("SELECT value FROM sync_state WHERE key='last_change_id'")->fetchColumn();try{applyClientsDeltaPage($page([$upsert(8,'22','Ошибка',['+79990000006'])],7,8),7,fn()=>throw new RuntimeException('synthetic crash'));throw new RuntimeException('Сбой до cursor не был вызван');}catch(RuntimeException $e){deltaExpect($e->getMessage()==='synthetic crash','Получена неожиданная ошибка');}
$after=(int)$pdo->query("SELECT value FROM sync_state WHERE key='last_change_id'")->fetchColumn();deltaExpect($before===$after&&!readClientMatchesCache('9990000006'),'Cursor или данные сохранились после сбоя');
applyClientsDeltaPage($page([$upsert(8,'22','После повтора',['+79990000006'])],7,8),7);deltaExpect(readClientMatchesCache('9990000006')[0]['name']==='После повтора','Повтор после сбоя не применился');
try{runClientsDeltaPages(8,fn()=>throw new RuntimeException('API unavailable'));throw new RuntimeException('Недоступность API пропущена');}catch(RuntimeException $e){deltaExpect($e->getMessage()==='API unavailable','Ошибка API искажена');}

// Успешно опубликованный cursor и промежуточная статистика не должны
// откатываться, если следующая страница стала недоступна.
writeClientsSyncState(['last_change_id'=>50]);
$partialPages=[50=>$page([$upsert(51,'partial-1','Сохранённый прогресс',['+79990000051'])],50,51,true)];
try {
    runClientsDeltaRefreshUnlocked('background_test',50,static function(int $after) use ($partialPages):array {
        if(isset($partialPages[$after]))return $partialPages[$after];
        throw new RuntimeException('temporary page failure');
    });
    throw new RuntimeException('Сбой после успешной страницы не был вызван');
} catch(RuntimeException $e) {
    deltaExpect($e->getMessage()==='temporary page failure','Ошибка следующей страницы искажена');
}
$partialState=readClientsSyncState();
deltaExpect((int)$partialState['last_change_id']===51,'Ошибка откатила cursor к началу запуска');
deltaExpect(($partialState['last_delta_status']??'')==='error'&&($partialState['pages_processed']??0)===1,'Промежуточная статистика страницы не сохранена');
deltaExpect(($partialState['last_error']??'')==='temporary page failure','Причина ошибки delta refresh не сохранена');
deltaExpect(($partialState['changes_processed']??0)===1&&($partialState['upserts']??0)===1&&($partialState['deletes']??0)===0,'Промежуточные счётчики повреждены');

$successState=runClientsDeltaRefreshUnlocked('background_test',51,fn(int $after)=>$page([$delete(52,'partial-1')],$after,52));
deltaExpect($successState['cursor']===52&&(readClientsSyncState()['last_delta_status']??'')==='success','Обычный delta refresh после возобновления не завершился');

// HTTP retry проверяется без реального ожидания и сетевых запросов.
$attempts=0;$delays=[];
$retryPayload=fetchClientsChangesJson('http://127.0.0.1:8015/api/clients/changes?token=secret',
    static function() use (&$attempts):array {
        $attempts++;
        if($attempts===1)return ['body'=>'temporary','status'=>502,'error'=>null];
        return ['body'=>'{"status":"success","items":[]}','status'=>200,'error'=>null];
    },
    static function(int $seconds) use (&$delays):void {$delays[]=$seconds;}
);
deltaExpect(($retryPayload['status']??'')==='success'&&$attempts===2&&$delays===[1],'Временная HTTP-ошибка не была повторена');

$attempts=0;
$networkPayload=fetchClientsChangesJson('http://clients.invalid/changes',static function() use (&$attempts):array {
    $attempts++;
    return $attempts===1
        ? ['body'=>false,'status'=>null,'error'=>'connection reset']
        : ['body'=>'{"status":"success"}','status'=>200];
},static function():void {});
deltaExpect(($networkPayload['status']??'')==='success'&&$attempts===2,'Сетевая ошибка без HTTP status не была повторена');

foreach([400,401,403,404] as $status) {
    $attempts=0;
    try {
        fetchClientsChangesJson('http://clients.invalid/changes?secret=value',static function() use (&$attempts,$status):array {$attempts++;return ['body'=>'error','status'=>$status];},static function():void {});
        throw new RuntimeException('HTTP '.$status.' ошибочно принят');
    } catch(RuntimeException $e) {
        deltaExpect($attempts===1,'HTTP '.$status.' не должен повторяться');
        deltaExpect(!str_contains($e->getMessage(),'secret=value'),'Query-параметры попали в диагностическую ошибку');
    }
}
foreach([408,429,500,502,503,504] as $status) {
    $attempts=0;$delays=[];
    try {
        fetchClientsChangesJson('http://clients.invalid/changes',static function() use (&$attempts,$status):array {$attempts++;return ['body'=>'error','status'=>$status];},static function(int $seconds) use (&$delays):void {$delays[]=$seconds;});
        throw new RuntimeException('HTTP '.$status.' ошибочно принят');
    } catch(RuntimeException $e) {
        deltaExpect($attempts===3&&$delays===[1,2],'HTTP '.$status.' должен иметь три попытки с backoff');
        deltaExpect(str_contains($e->getMessage(),'попыток 3'),'Итоговая retry-диагностика неполна');
    }
}
$attempts=0;
try {
    fetchClientsChangesJson('http://clients.invalid/changes',static function() use (&$attempts):array {$attempts++;return ['body'=>'{"status":"error","message":"cursor expired"}','status'=>200];},static function():void {});
    throw new RuntimeException('Ошибка cursor ошибочно принята');
} catch(RuntimeException $e) {
    deltaExpect($attempts===1&&str_starts_with($e->getMessage(),'Требуется полное обновление кэша:'),'Ошибка cursor должна требовать full refresh без retry');
}

// Snapshot cursor и последовательный catch-up моделируют изменения, появившиеся во время full.
$snapshot=8;$catchup=runClientsDeltaPages($snapshot,fn(int $after)=>$page([$upsert(9,'23','Во время full',['+79990000007'])],$after,9));deltaExpect($catchup['cursor']===9&&count(readClientMatchesCache('9990000007'))===1,'Full snapshot + catch-up потерял изменение');
$lock=fopen(clientsRefreshLockFile(),'c');flock($lock,LOCK_EX|LOCK_NB);deltaExpect(clientsRefreshIsLocked(),'Общая блокировка full/delta не работает');flock($lock,LOCK_UN);fclose($lock);
$installer=file_get_contents(dirname(__DIR__).'/scripts/install_clients_cache_cron.sh');deltaExpect(str_contains($installer,'0 4 * * *')&&str_contains($installer,' delta '),'Daily cron не запускает delta');deltaExpect(str_contains($installer,'0 3 * * 0')&&str_contains($installer,' full '),'Weekly cron не запускает full');

foreach([0,10,500,2000] as $count){$start=microtime(true);$items=[];$cursor=10000+$count;for($i=1;$i<=$count;$i++)$items[]=$upsert($cursor+$i,'p'.$count.'-'.$i,'Perf '.$i,['+7'.str_pad((string)($count*2000+$i),10,'0',STR_PAD_LEFT)]);applyClientsDeltaPage($page($items,$cursor,$cursor+$count),$cursor);printf("PERF changes=%d time=%.4fs memory=%.1fMB\n",$count,microtime(true)-$start,memory_get_peak_usage(true)/1048576);}
putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
applyClientsDeltaPage($page([$upsert(30001,'file-1','Без SQLite',['+79991112233'])],30000,30001),30000);
deltaExpect(readClientMatchesCache('9991112233')[0]['name']==='Без SQLite','Файловый backend не применил upsert');
applyClientsDeltaPage($page([$delete(30002,'file-1')],30001,30002),30001);
deltaExpect(readClientMatchesCache('9991112233')===[],'Файловый backend не применил delete');
echo "clients_delta_refresh_test: OK\n";
