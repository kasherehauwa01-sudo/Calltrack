<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog.php';
require_once __DIR__.'/sales_journal.php';

function clientCatalogPurchasesDirectory(): string
{
    $storage=trim((string)(getenv('CALLTRACK_STORAGE_DIR')?:''));
    $path=($storage!==''?rtrim($storage,'/'):dirname(__DIR__).'/storage').'/cache/client-catalog';
    if(!is_dir($path)&&!@mkdir($path,0775,true)&&!is_dir($path))throw new RuntimeException('Не удалось подготовить кеш дат покупок');
    return $path;
}
function clientCatalogPurchasesRead(string $name): ?array
{
    $raw=@file_get_contents(clientCatalogPurchasesDirectory().'/'.$name.'.json');
    if($raw===false)return null;$data=json_decode($raw,true);return is_array($data)?$data:null;
}
function clientCatalogPurchasesWrite(string $name,array $data): void
{
    $path=clientCatalogPurchasesDirectory().'/'.$name.'.json';$temporary=$path.'.'.getmypid().'.tmp';
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(@file_put_contents($temporary,$json,LOCK_EX)===false||!@rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Не удалось сохранить даты покупок');}
}

/** All-time histories, batched by exact name using the existing Sales Journal contract. */
function clientCatalogPurchaseDates(array $records,?callable $transport=null,?callable $progress=null): array
{
    $clients=[];$idsByKey=[];$namesByKey=[];$dates=array_fill_keys(array_keys($records),null);
    foreach($records as $id=>$record){
        $name=normalizeSalesJournalClientName($record['name']);if($name==='')continue;
        $key='name:'.hash('sha256',$name);$idsByKey[$key][]=$id;$namesByKey[$key]=$name;
        $clients[$key]=['key'=>$key,'id'=>'','phone'=>'','name'=>$record['name']];
    }
    $done=0;
    foreach(array_chunk(array_values($clients),100) as $chunk){
        $result=$transport?loadSalesJournalBatch($chunk,'0001-01-01','9999-12-31',$transport):loadSalesJournalBatchCached($chunk,'0001-01-01','9999-12-31');
        foreach($result['items'] as $sale){
            $key=$sale['client_key'];if(!isset($idsByKey[$key])||normalizeSalesJournalClientName($sale['client'])!==$namesByKey[$key])throw new RuntimeException('Несогласованная история покупок');
            $date=salesJournalDate($sale['sale_date']);
            foreach($idsByKey[$key] as $id)if($dates[$id]===null||$dates[$id]<$date)$dates[$id]=$date;
        }
        $done+=count($chunk);if($progress)$progress($done,count($clients));
    }
    return $dates;
}

/** Only CLI performs remote I/O; no histories are fetched in the list HTTP request. */
function runClientCatalogPurchasesRefresh(): void
{
    $lock=fopen(clientCatalogPurchasesDirectory().'/purchases-refresh.lock','c');
    if($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);return;}
    $revision=clientCatalogRevision();
    try{
        $records=clientCatalogRecords();
        $status=['status'=>'running','revision'=>$revision,'updated_at'=>time(),'processed'=>0,'total'=>count($records)];
        clientCatalogPurchasesWrite('purchases-status',$status);
        $dates=clientCatalogPurchaseDates($records,null,static function($done,$total)use(&$status){$status['processed']=$done;$status['total']=$total;$status['updated_at']=time();clientCatalogPurchasesWrite('purchases-status',$status);});
        if(clientCatalogRevision()!==$revision)throw new RuntimeException('Справочник обновился во время расчёта');
        clientCatalogPurchasesWrite('purchases-index',['version'=>1,'revision'=>$revision,'updated_at'=>time(),'dates'=>$dates]);
        clientCatalogPurchasesWrite('purchases-status',['status'=>'ready','revision'=>$revision,'updated_at'=>time(),'processed'=>count($dates),'total'=>count($dates)]);
    }catch(Throwable $error){
        clientCatalogPurchasesWrite('purchases-status',['status'=>'error','revision'=>$revision,'updated_at'=>time()]);
        error_log('Client catalog purchase dates: '.$error->getMessage());
        throw $error;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function startClientCatalogPurchasesRefresh(string $revision,bool $retry=false): void
{
    $gate=fopen(clientCatalogPurchasesDirectory().'/purchases-start.lock','c');
    if($gate===false||!flock($gate,LOCK_EX|LOCK_NB)){if(is_resource($gate))fclose($gate);return;}
    try{
        $running=fopen(clientCatalogPurchasesDirectory().'/purchases-refresh.lock','c');
        if($running===false)throw new RuntimeException('Не удалось запустить расчёт покупок');
        if(!flock($running,LOCK_EX|LOCK_NB)){fclose($running);return;}
        flock($running,LOCK_UN);fclose($running);
        $status=clientCatalogPurchasesRead('purchases-status');
        if(($status['revision']??'')===$revision){
            if(($status['status']??'')==='starting'&&($status['updated_at']??0)>time()-15)return;
            if(($status['status']??'')==='error'&&($status['updated_at']??0)>time()-60&&!$retry)return;
        }
        $php='';foreach(array_filter([getenv('CALLTRACK_PHP_CLI'),'/usr/bin/php',PHP_BINDIR.'/php']) as $candidate)if(is_file($candidate)&&is_executable($candidate)){$php=$candidate;break;}
        if($php===''||!function_exists('exec'))throw new RuntimeException('Для обновления дат покупок требуется PHP CLI и exec');
        clientCatalogPurchasesWrite('purchases-status',['status'=>'starting','revision'=>$revision,'updated_at'=>time(),'processed'=>0,'total'=>0]);
        $command=sprintf('nohup %s %s </dev/null >/dev/null 2>&1 & echo $!',escapeshellarg($php),escapeshellarg(__DIR__.'/refresh_client_purchase_dates.php'));
        exec($command,$output,$code);
        if($code!==0||!ctype_digit(trim((string)($output[0]??''))))throw new RuntimeException('Не удалось запустить расчёт покупок');
    }catch(Throwable $error){
        clientCatalogPurchasesWrite('purchases-status',['status'=>'error','revision'=>$revision,'updated_at'=>time()]);
        throw $error;
    }finally{flock($gate,LOCK_UN);fclose($gate);}
}

function clientCatalogPurchasesState(bool $retry=false): array
{
    $revision=clientCatalogRevision();$index=clientCatalogPurchasesRead('purchases-index');
    $valid=($index['version']??null)===1&&($index['revision']??'')===$revision&&is_array($index['dates']??null);
    $fresh=$valid&&($index['updated_at']??0)>time()-600;
    if(!$fresh)startClientCatalogPurchasesRefresh($revision,$retry);
    $status=clientCatalogPurchasesRead('purchases-status');
    if(!$valid&&($status['revision']??'')===$revision&&($status['status']??'')==='error')throw new RuntimeException('Не удалось обновить даты покупок. Повторите попытку.');
    return ['ready'=>$valid,'dates'=>$valid?$index['dates']:[], 'state'=>$fresh?'ready':($valid?'refreshing':'warming'),
        'updated_at'=>$valid?(int)$index['updated_at']:null,'processed'=>(int)($status['processed']??0),'total'=>(int)($status['total']??0)];
}
