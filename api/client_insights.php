<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';
require_once __DIR__.'/purchase_analytics.php';

function clientHistoryInsights(array $context, ?callable $transport=null): array
{
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
