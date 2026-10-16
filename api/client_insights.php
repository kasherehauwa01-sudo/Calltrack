<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';
require_once __DIR__.'/purchase_analytics.php';

function clientHistoryInsights(array $context, ?callable $transport=null): array
{
    if(array_key_exists('client_keys',$context)){
        $selected=clientHistorySelectedClients($context);
        if(count($selected)===1){unset($context['client_keys']);$context['client_key']=$selected[0]['key'];return clientHistoryInsights($context,$transport);}
        $parts=[];
        foreach($selected as $candidate){$single=$context;unset($single['client_keys']);$single['client_key']=$candidate['key'];$parts[$candidate['name']]=clientHistoryInsights($single,$transport);}
        return clientHistoryMergeInsights($parts);
    }
    $selection=clientHistorySelection($context,(string)($context['client_key']??''));
    $name=(string)($selection['selected']['name']??'');
    if($name==='')throw new InvalidArgumentException('Для сводки нужно наименование клиента');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    $key=hash('sha256','insights-v1|'.$today.'|'.normalizeSalesJournalClientName($name));
    $file=salesJournalCacheDirectory().'/insights-'.$key.'.json';
    $modified=@filemtime($file);$cached=$modified===false?null:json_decode((string)@file_get_contents($file),true);
    if(is_array($cached) && $modified>=time()-SALES_JOURNAL_CACHE_TTL)return $cached+['cache_stale'=>false];
    try{
        $result=salesJournalRequest('POST','/api/integrations/calltrack/client-insights',['name'=>$name,'as_of'=>$today],$transport);
        if(!isset($result['sale_quantities'],$result['unique_products'],$result['top_categories']) || !array_key_exists('rfm',$result))throw new RuntimeException('Некорректная сводка Sales Journal');
        $temporary=$file.'.'.getmypid().'.tmp';
        if(@file_put_contents($temporary,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)!==false)@rename($temporary,$file);
        return $result+['cache_stale'=>false];
    }catch(Throwable $error){
        if(is_array($cached) && $modified>=time()-SALES_JOURNAL_STALE_CACHE_TTL)return $cached+['cache_stale'=>true];
        throw $error;
    }
}

/** Merge available categories; expose truncation instead of hiding usable rows. */
function clientHistoryMergeInsights(array $parts): array
{
    $categories=[];$quantities=[];$stale=false;$complete=true;
    $unique=[];$rfm=[];
    foreach($parts as $name=>$part){
        $stale=$stale||($part['cache_stale']??false);
        $complete=$complete&&($part['categories_complete']??(count($part['top_categories'])<20));
        $unique[]=['name'=>$name,'count'=>$part['unique_products']];
        $rfm[]=['name'=>$name,'segment'=>$part['rfm']['segment']??'Недоступно'];
        foreach($part['sale_quantities'] as $row){
            $id=(int)$row['sale_id'];
            if(isset($quantities[$id])&&$quantities[$id]['quantity']!==$row['quantity'])throw new RuntimeException('Несогласованный состав общей продажи');
            $quantities[$id]=$row;
        }
        foreach($part['top_categories'] as $row){
            $key=(string)$row['category'];
            if(!isset($categories[$key]))$categories[$key]=['category'=>$key,'revenue'=>0,'quantity'=>0];
            foreach(['revenue','quantity'] as $field){
                $value=(string)$row[$field];
                if(!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,6}))?$/D',$value,$m))throw new RuntimeException('Некорректный показатель категории');
                $scaled=(int)$m[2]*1000000+(int)str_pad($m[3]??'',6,'0');
                $categories[$key][$field]=purchaseAdd($categories[$key][$field],$m[1]==='-'?-$scaled:$scaled);
            }
        }
    }
    uasort($categories,static fn($a,$b)=>$b['revenue']<=>$a['revenue']);
    foreach($categories as &$row)foreach(['revenue','quantity'] as $field){$v=$row[$field];$row[$field]=($v<0?'-':'').intdiv(abs($v),1000000).'.'.str_pad((string)(abs($v)%1000000),6,'0',STR_PAD_LEFT);}unset($row);
    return ['unique_products'=>$parts?null:0,'unique_products_by_client'=>$unique,'rfm'=>null,'rfm_by_client'=>$rfm,
        'top_categories'=>array_slice(array_values($categories),0,20),'categories_complete'=>$complete,'uncategorized_products'=>null,
        'sale_quantities'=>array_values($quantities),'cache_stale'=>$stale,
        'notice'=>$complete?'':'Показан топ-20 по доступным категориям выбранных клиентов. Sales Journal возвращает только топ-20 каждого клиента: суммы, количества и доли могут быть неполными, а общий порядок категорий — отличаться.'];
}

function purchaseLifetime(array $sales, ?DateTimeImmutable $today=null): array
{
    $today=$today??new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow'));
    $dates=array_values(array_unique(array_column($sales,'sale_date')));sort($dates,SORT_STRING);
    $first=$dates[0]??null;$last=$dates?end($dates):null;$cents=0;
    foreach($sales as $sale)$cents=purchaseAdd($cents,$sale['amount_cents']);
    $zone=new DateTimeZone('Europe/Moscow');
    $span=$first?(new DateTimeImmutable($first,$zone))->diff(new DateTimeImmutable($last,$zone))->days:0;
    $recency=$last?(int)(new DateTimeImmutable($last,$zone))->diff($today)->format('%r%a'):null;
    return ['sales_count'=>count($sales),'revenue'=>purchaseMoney($cents),'first_purchase'=>$first,'last_purchase'=>$last,
        'average_interval'=>count($dates)>1?round($span/(count($dates)-1),1):null,'days_without_purchase'=>$recency===null?null:max(0,$recency)];
}

function purchaseAttachQuantities(array $sales,array $insights): array
{
    $quantities=[];
    foreach($insights['sale_quantities'] as $row)$quantities[(int)$row['sale_id']]=(string)$row['quantity'];
    foreach($sales as &$sale){
        if(!array_key_exists($sale['sale_id'],$quantities))throw new RuntimeException('Состав продаж изменился. Повторите загрузку позже.');
        $sale['quantity']=$quantities[$sale['sale_id']];
    }unset($sale);
    return $sales;
}
