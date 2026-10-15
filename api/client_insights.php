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
    // Sales Journal owns the persistent RFM snapshot; never cache it in Calltrack.
    $result=salesJournalRequest('POST','/api/integrations/calltrack/client-insights',['name'=>$name,'as_of'=>$today],$transport);
    return clientHistoryNormalizeInsights($result)+['cache_stale'=>false];
}

/** Additive snapshot metadata is optional; internal IDs never reach the UI. */
function clientHistoryNormalizeInsights(array $result): array
{
    if(!isset($result['sale_quantities'],$result['unique_products'],$result['top_categories'])
        || !is_array($result['sale_quantities']) || !is_int($result['unique_products'])
        || $result['unique_products']<0 || !is_array($result['top_categories'])) {
        throw new RuntimeException('Некорректная сводка Sales Journal');
    }
    foreach($result['top_categories'] as $row){
        if(!is_array($row) || !is_string($row['category']??null)
            || !is_numeric($row['revenue']??null) || !is_numeric($row['quantity']??null)) {
            throw new RuntimeException('Некорректные категории Sales Journal');
        }
    }
    foreach($result['sale_quantities'] as $row){
        if(!is_array($row) || !is_numeric($row['sale_id']??null) || !is_numeric($row['quantity']??null)) {
            throw new RuntimeException('Некорректные количества Sales Journal');
        }
    }
    $public=array_intersect_key($result,array_flip(['sale_quantities','unique_products','top_categories','uncategorized_products','as_of']));
    $rfm=$result['rfm']??null;
    if($rfm!==null && !is_array($rfm))throw new RuntimeException('Некорректный RFM Sales Journal');
    $public['rfm']=$rfm===null?null:array_intersect_key($rfm,array_flip(['segment','code','tags','recency','frequency','monetary']));
    foreach(['segment','code'] as $field){
        if(isset($public['rfm'][$field]) && !is_scalar($public['rfm'][$field]))throw new RuntimeException('Некорректный RFM Sales Journal');
    }
    foreach(['calculated_at','period_from','period_to'] as $field){
        $value=$result[$field]??($rfm[$field]??null);
        if(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:$|T| )/D',$value))$public[$field]=$value;
    }
    $status=$result['rfm_status']??($rfm['status']??null);
    $public['rfm_status']=in_array($status,['unavailable','snapshot_missing','client_missing','stale'],true)
        ?$status:($public['rfm']===null?'unavailable':'available');
    if(in_array($public['rfm_status'],['unavailable','snapshot_missing','client_missing'],true))$public['rfm']=null;
    return $public;
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
