<?php
declare(strict_types=1);
require_once __DIR__.'/sales_journal.php';
try {
    $pdo=getPdo(); $user=requireWebUser($pdo);
    $year=filter_var($_GET['year']??'', FILTER_VALIDATE_INT);
    if(!$year||$year<2000||$year>2100)throw new InvalidArgumentException('Некорректный год');
    $managers=json_decode((string)($_GET['managers']??'[]'),true);
    if(!is_array($managers)||count($managers)>500)throw new InvalidArgumentException('Некорректные менеджеры');
    foreach($managers as $manager)if(!is_string($manager)||mb_strlen($manager)>512)throw new InvalidArgumentException('Некорректный менеджер');
    $managers=array_values(array_unique(array_filter(array_map('strval',$managers))));
    $scope=webManagerScope($user);
    if($scope)$managers=in_array($scope['manager'],$managers,true)?[$scope['manager']]:[];
    $result=salesJournalRequest('POST','/api/integrations/calltrack/dashboard-sales',['year'=>$year,'managers'=>$managers]);
    if(!isset($result['items'])||!is_array($result['items'])||count($result['items'])!==12)throw new RuntimeException('Некорректный ответ dashboard-sales');
    sendJson(['status'=>'success','items'=>$result['items']]);
} catch(InvalidArgumentException $e) {
    sendJson(['status'=>'error','message'=>$e->getMessage()],400);
} catch(Throwable $e) {
    error_log('Dashboard sales unavailable: '.$e->getMessage());
    $message=$e->getMessage()==='Sales Journal HTTP 404'
        ?'Для диаграммы продаж требуется обновление backend журнала продаж'
        :'Продажи временно недоступны';
    sendJson(['status'=>'error','message'=>$message],503);
}
