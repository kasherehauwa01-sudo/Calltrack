<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';
require_once __DIR__.'/client_insights.php';
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sendJson(['status'=>'error','message'=>'Метод не поддерживается'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$input=readJsonBody();
    $context=clientHistoryContext($pdo,$user,$input);
    $public=['found_in_1c'=>$context['found_in_1c'],'phone'=>$context['phone'],'can_mark_personal'=>!$context['found_in_1c'] && $context['phone']!=='' && $context['call_id']!==null && $context['user_phone']!==''];
    $selection=clientHistorySelection($context,trim((string)($input['client_key']??'')));
    $public['candidates']=array_map(static fn($c)=>['key'=>$c['key'],'name'=>$c['name'],'id'=>$c['id']],$selection['candidates']);
    $public['selected_client_key']=$selection['selected']['key']??'';
    $public['selection_required']=$selection['selection_required'];
    $context['client_key']=$public['selected_client_key'];
    if($selection['selection_required'])sendJson(['status'=>'success','context'=>$public]);
    if(($input['action']??'')==='context')sendJson(['status'=>'success','context'=>$public,'period'=>purchaseDefaultPeriod()]);
    $metric=(string)($input['metric']??'revenue');
    $defaults=purchaseDefaultPeriod();$from=(string)($input['from']??$defaults['from']);$to=(string)($input['to']??$defaults['to']);$detail=(string)($input['detail']??'month');
    purchaseAnalytics([],$from,$to,$detail,$metric); // Validate before upstream I/O.
    $result=clientHistorySales($context);$sales=purchaseDataset($result['items']);
    if(($input['action']??'')==='summary'){
        $summary=purchaseLifetime($sales);$insights=null;$detailsError=null;
        try{$insights=clientHistoryInsights($context);}catch(Throwable $e){error_log('Client insights: '.$e->getMessage());$detailsError=str_contains($e->getMessage(),'HTTP 404')?'Обновите backend Sales Journal: API товарных показателей и RFM ещё не установлен.':'Не удалось получить товарные показатели и RFM из Sales Journal. Повторите загрузку.';}
        if($insights)$summary+=array_intersect_key($insights,array_flip(['unique_products','top_categories','uncategorized_products','rfm','as_of']));
        sendJson(['status'=>'success','context'=>$public,'summary'=>$summary,'details_error'=>$detailsError,'cache_stale'=>$result['cache_stale']||($insights['cache_stale']??false)]);
    }
    if(in_array($metric,['units','units_per_sale'],true)){
        $insights=clientHistoryInsights($context);$sales=purchaseAttachQuantities($sales,$insights);$result['cache_stale']=$result['cache_stale']||$insights['cache_stale'];
    }
    allowSalesJournalDetails($result['items']);
    $response=['status'=>'success','context'=>$public,'analytics'=>purchaseAnalytics($sales,$from,$to,$detail,$metric),'cache_stale'=>$result['cache_stale'],'requests'=>$result['requests'],'excluded_count'=>$result['excluded_count']];
    if(($input['action']??'')!=='analytics')$response['tree']=purchaseTree($sales);
    sendJson($response);
} catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Client purchases: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить данные клиента. Повторите попытку позже.'],502);}
