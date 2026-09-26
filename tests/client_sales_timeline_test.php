<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/api/sales_journal.php';

function salesAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

salesAssert(salesJournalClientKey(['id'=>'123','phone'=>'+79990000000','name'=>'ООО Ромашка'])==='phone:+79990000000','Телефон не имеет приоритет в client_key');
salesAssert(salesJournalClientKey(['phone'=>'8 (999) 123-45-67','name'=>'ООО Ромашка'])==='phone:+79991234567','Телефон fallback не нормализован');
salesAssert(salesJournalClientKey(['name'=>'ООО Ромашка'])!==salesJournalClientKey(['name'=>'ООО Ромашка Плюс']),'Похожие названия ошибочно объединены');
$duplicates=canonicalizeSalesJournalClients([
    ['key'=>'client:1','id'=>'1','phone'=>'+79000000000','name'=>'Клиент'],
    ['key'=>'client:2','id'=>'2','phone'=>'8 (900) 000-00-00','name'=>'Клиент'],
    ['key'=>'client:3','id'=>'3','phone'=>'79000000000','name'=>'Клиент'],
    ['key'=>'client:4','id'=>'4','phone'=>'+79000000000','name'=>'Клиент'],
]);
salesAssert(count($duplicates)===1&&$duplicates[0]['key']==='phone:+79000000000','Дубли client:id не объединены по телефону');
salesAssert($duplicates[0]['source_keys']===['client:1','client:2','client:3','client:4'],'Потеряна связь canonical client с исходными client:id');
$sameNames=canonicalizeSalesJournalClients([['id'=>'1','phone'=>'+79000000001','name'=>'Одинаковое имя'],['id'=>'2','phone'=>'+79000000002','name'=>'Одинаковое имя']]);
salesAssert(count($sameNames)===2,'Разные телефоны ошибочно объединены по имени');
$withoutPhone=canonicalizeSalesJournalClients([['id'=>'7','phone'=>'','name'=>'Точное имя']]);
salesAssert($withoutPhone[0]['key']==='client:7'&&$withoutPhone[0]['name']==='Точное имя','Exact-name fallback потерял имя или стабильный ID');
salesAssert(canonicalizeSalesJournalClients([['phone'=>'','name'=>'']])===[],'Полностью пустой клиент не исключён');
$productionDuplicates=[];foreach([
    '9033161409'=>['367642','367643'],'9064015297'=>['335899','335902','335903'],'9889808336'=>['418287','418288','418289','418290'],
    '9616797416'=>['326316','326317'],'9061701434'=>['300231','300232','300233'],'9377373882'=>['327762','327763'],
] as $phone=>$ids)foreach($ids as $id)$productionDuplicates[]=['key'=>'client:'.$id,'id'=>$id,'phone'=>$phone,'name'=>'Production regression'];
$canonicalProduction=canonicalizeSalesJournalClients($productionDuplicates);
salesAssert(count($canonicalProduction)===6&&count(array_unique(array_column($canonicalProduction,'phone')))===6,'Production-дубли телефонов остались в payload');
salesAssert(salesJournalPeriod(['date_from'=>'2026-09-01','date_to'=>'2026-09-30'])===['2026-09-01','2026-09-30'],'Период изменён');
try{salesJournalPeriod(['date_from'=>'2026-10-01','date_to'=>'2026-09-30']);throw new RuntimeException('Обратный период принят');}catch(InvalidArgumentException $expected){}
foreach([[401,'{}',''],[500,'{}',''],[0,'','timeout']] as [$status,$raw,$error]){try{salesJournalDecodeResponse($raw,$status,$error);throw new RuntimeException("Ошибка Sales Journal {$status} принята");}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'Sales Journal'),'Ошибка скрыта неверно');}}
try{salesJournalDecodeResponse('{bad',200);throw new RuntimeException('Invalid JSON принят');}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'JSON'),'Invalid JSON не распознан');}
salesAssert(salesJournalDecodeResponse('{"items":[]}',200)===['items'=>[]],'Пустой список продаж не поддерживается');
try{salesJournalDecodeResponse('{"detail":"duplicate phone"}',422);throw new RuntimeException('HTTP 422 принят');}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'422'),'HTTP 422 не обрабатывается как недоступность продаж');}

$clients=[];for($i=1;$i<=501;$i++)$clients[]=['key'=>'client:'.$i,'phone'=>'+7999'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'name'=>'Клиент '.$i];
$requests=[];$transport=static function(string $method,string $url,?array $body)use(&$requests):array{$requests[]=$body;return ['items'=>[['sale_id'=>77,'client_key'=>$body['clients'][0]['key'],'matched_by'=>'phone','sale_date'=>$body['date_from'],'document_number'=>'Р-77','client'=>'Клиент','phone'=>'+79990000000','manager'=>null,'department'=>'Авиаторов','total_amount'=>'100.50']]];};
$result=loadSalesJournalBatch($clients,'2026-09-01','2026-09-30',$transport);
salesAssert(count($requests)===2&&count($requests[0]['clients'])===500&&count($requests[1]['clients'])===1,'Batch >500 не разбит последовательно');
salesAssert($result['requests']===2&&count($result['items'])===1,'sale_id не дедуплицируется между batch');
salesAssert($requests[0]['date_from']==='2026-09-01'&&$requests[0]['date_to']==='2026-09-30','Границы периода не переданы Sales Journal');
$rawForDedup=[];for($i=1;$i<=500;$i++)$rawForDedup[]=['key'=>'client:'.$i,'id'=>(string)$i,'phone'=>'+7988'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'name'=>'Клиент'];$rawForDedup[]=['key'=>'client:duplicate','id'=>'duplicate','phone'=>$rawForDedup[0]['phone'],'name'=>'Клиент'];
$dedupRequests=[];loadSalesJournalBatch($rawForDedup,'2026-09-01','2026-09-30',static function($method,$url,$body)use(&$dedupRequests){$dedupRequests[]=$body;return ['items'=>[]];});
salesAssert(count($dedupRequests)===1&&count($dedupRequests[0]['clients'])===500,'Дедупликация выполнена после batching');

$root=dirname(__DIR__);$html=(string)file_get_contents($root.'/analizmop/index.html');$api=(string)file_get_contents($root.'/analizmop/api.js');$batch=(string)file_get_contents($root.'/api/client_sales.php');$detail=(string)file_get_contents($root.'/api/sale_detail.php');$integration=(string)file_get_contents($root.'/api/sales_journal.php');
foreach(['clientSales','saleDetail','getClientSales','getSaleDetail'] as $required)salesAssert(str_contains($api,$required),"Frontend API не содержит {$required}");
foreach(['callSegments','emailSegments','saleSegments','events.sort','data-sale-id','Продаж:','refreshClientSalesTimeline'] as $required)salesAssert(str_contains($html,$required),"Timeline не содержит {$required}");
foreach(['sale_id','client_key','matched_by','sale_date','document_number','total_amount'] as $required)salesAssert(str_contains($integration,$required),"Sale Summary не содержит {$required}");
foreach(['network error','HTTP ','некорректный JSON','SALES_JOURNAL_CONNECT_TIMEOUT','SALES_JOURNAL_TIMEOUT'] as $required)salesAssert(str_contains($integration,$required),"Нет обработки Sales Journal: {$required}");
salesAssert(str_contains($batch,'requireWebUser($pdo)')&&str_contains($batch,'salesJournalCommunicationClients($pdo,$user'),'Batch не применяет web scope');
salesAssert(str_contains($detail,'requireWebUser($pdo)')&&str_contains($detail,'isSalesJournalDetailAllowed'),'Detail позволяет IDOR');
salesAssert(str_contains($batch,"'available'=>false")&&str_contains($html,'Данные о продажах временно недоступны'),'Нет graceful degradation');
salesAssert(str_contains($html,"items.length?")&&str_contains($html,'Распознанные товары отсутствуют'),'Пустой items ломает карточку');
salesAssert(str_contains($html,'quantity||0)*Number(item.actual_price||0'),'Сумма товара не рассчитывается');
salesAssert(str_contains($html,'escapeHtml(item.name')&&str_contains($html,'escapeHtml(sale.original_products_text)'),'Внешние данные выводятся без экранирования');
salesAssert(!str_contains($html,'CALLTRACK_INTEGRATION_TOKEN')&&!str_contains($api,'CALLTRACK_INTEGRATION_TOKEN'),'Bearer token попал во frontend');
salesAssert(substr_count($integration,"salesJournalRequest('POST'")===1,'Реализован N+1 вместо одного batch-цикла');
salesAssert(str_contains($integration,'array_chunk($clients,500)'),'Нет ограничения batch в 500 клиентов');
salesAssert(str_contains($integration,'manager=:manager')&&str_contains($integration,'user_phone=:user_phone'),'Manager scope не применяется к коммуникациям');
salesAssert(str_contains($html,'type:0')&&str_contains($html,'sale.sale_date'),'Date-only продажа не имеет стабильного порядка');
salesAssert(str_contains($html,'keys.length===1?rows[0]:null')&&str_contains($html,'phone:+7${phone}'),'Frontend не связывает canonical phone key с существующей UI-группой');
$config=(string)file_get_contents($root.'/api/config.php');salesAssert(str_contains($config,"SALES_JOURNAL_TIMEOUT') ?: 30")&&str_contains($config,'SALES_JOURNAL_CONNECT_TIMEOUT'),'Timeout не настраивается или снова меньше production latency');

echo "client_sales_timeline_test: OK\n";
