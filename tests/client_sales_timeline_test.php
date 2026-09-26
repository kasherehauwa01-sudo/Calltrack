<?php
declare(strict_types=1);

define('SALES_JOURNAL_BASE_URL','https://sales.invalid');
define('CALLTRACK_INTEGRATION_TOKEN','test-token-not-production');
require_once dirname(__DIR__).'/api/sales_journal.php';

function salesAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

salesAssert(salesJournalClientKey(['id'=>'123','phone'=>'+79990000000','name'=>'ООО Ромашка'])==='client:123','Стабильный Clients ID не используется в client_key');
salesAssert(salesJournalClientKey(['phone'=>'8 (999) 123-45-67','name'=>'ООО Ромашка'])==='phone:+79991234567','Телефон fallback не нормализован');
salesAssert(salesJournalClientKey(['name'=>'ООО Ромашка'])!==salesJournalClientKey(['name'=>'ООО Ромашка Плюс']),'Похожие названия ошибочно объединены');
salesAssert(salesJournalPeriod(['date_from'=>'2026-09-01','date_to'=>'2026-09-30'])===['2026-09-01','2026-09-30'],'Период изменён');
try{salesJournalPeriod(['date_from'=>'2026-10-01','date_to'=>'2026-09-30']);throw new RuntimeException('Обратный период принят');}catch(InvalidArgumentException $expected){}
foreach([[401,'{}',''],[500,'{}',''],[0,'','timeout']] as [$status,$raw,$error]){try{salesJournalDecodeResponse($raw,$status,$error);throw new RuntimeException("Ошибка Sales Journal {$status} принята");}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'Sales Journal'),'Ошибка скрыта неверно');}}
try{salesJournalDecodeResponse('{bad',200);throw new RuntimeException('Invalid JSON принят');}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'JSON'),'Invalid JSON не распознан');}
salesAssert(salesJournalDecodeResponse('{"items":[]}',200)===['items'=>[]],'Пустой список продаж не поддерживается');

$clients=[];for($i=1;$i<=501;$i++)$clients[]=['key'=>'client:'.$i,'phone'=>'+7999'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'name'=>'Клиент '.$i];
$requests=[];$transport=static function(string $method,string $url,?array $body)use(&$requests):array{$requests[]=$body;return ['items'=>[['sale_id'=>77,'client_key'=>$body['clients'][0]['key'],'matched_by'=>'phone','sale_date'=>$body['date_from'],'document_number'=>'Р-77','client'=>'Клиент','phone'=>'+79990000000','manager'=>null,'department'=>'Авиаторов','total_amount'=>'100.50']]];};
$result=loadSalesJournalBatch($clients,'2026-09-01','2026-09-30',$transport);
salesAssert(count($requests)===2&&count($requests[0]['clients'])===500&&count($requests[1]['clients'])===1,'Batch >500 не разбит последовательно');
salesAssert($result['requests']===2&&count($result['items'])===1,'sale_id не дедуплицируется между batch');
salesAssert($requests[0]['date_from']==='2026-09-01'&&$requests[0]['date_to']==='2026-09-30','Границы периода не переданы Sales Journal');

$root=dirname(__DIR__);$html=(string)file_get_contents($root.'/analizmop/index.html');$api=(string)file_get_contents($root.'/analizmop/api.js');$batch=(string)file_get_contents($root.'/api/client_sales.php');$detail=(string)file_get_contents($root.'/api/sale_detail.php');$integration=(string)file_get_contents($root.'/api/sales_journal.php');
foreach(['clientSales','saleDetail','getClientSales','getSaleDetail'] as $required)salesAssert(str_contains($api,$required),"Frontend API не содержит {$required}");
foreach(['callSegments','emailSegments','saleSegments','events.sort','data-sale-id','Продаж:','refreshClientSalesTimeline'] as $required)salesAssert(str_contains($html,$required),"Timeline не содержит {$required}");
foreach(['sale_id','client_key','matched_by','sale_date','document_number','total_amount'] as $required)salesAssert(str_contains($integration,$required),"Sale Summary не содержит {$required}");
foreach(['network error','HTTP ','некорректный JSON','CURLOPT_CONNECTTIMEOUT=>2','CURLOPT_TIMEOUT=>6'] as $required)salesAssert(str_contains($integration,$required),"Нет обработки Sales Journal: {$required}");
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

echo "client_sales_timeline_test: OK\n";
