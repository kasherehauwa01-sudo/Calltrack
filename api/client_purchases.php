<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';
require_once __DIR__.'/purchase_analytics.php';
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sendJson(['status'=>'error','message'=>'Метод не поддерживается'],405);
    $pdo=getPdo();$user=requireWebUser($pdo);$input=readJsonBody();
    $context=clientHistoryContext($pdo,$user,$input);
    $public=['found_in_1c'=>$context['found_in_1c'],'phone'=>$context['phone'],'can_mark_personal'=>!$context['found_in_1c'] && $context['phone']!=='' && $context['call_id']!==null && $context['user_phone']!==''];
    if(($input['action']??'')==='context')sendJson(['status'=>'success','context'=>$public,'period'=>purchaseDefaultPeriod()]);
    $defaults=purchaseDefaultPeriod();$from=(string)($input['from']??$defaults['from']);$to=(string)($input['to']??$defaults['to']);$detail=(string)($input['detail']??'month');
    purchaseAnalytics([],$from,$to,$detail); // Validate before upstream I/O.
    $result=clientHistorySales($context);$sales=purchaseDataset($result['items']);
    allowSalesJournalDetails($result['items']);
    $response=['status'=>'success','context'=>$public,'analytics'=>purchaseAnalytics($sales,$from,$to,$detail),'cache_stale'=>$result['cache_stale'],'requests'=>$result['requests']];
    if(($input['action']??'')!=='analytics')$response['tree']=purchaseTree($sales);
    sendJson($response);
} catch(InvalidArgumentException $e){sendJson(['status'=>'error','message'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('Client purchases: '.$e->getMessage());sendJson(['status'=>'error','message'=>'Не удалось загрузить данные клиента. Повторите попытку позже.'],502);}
