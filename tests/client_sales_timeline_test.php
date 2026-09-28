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
foreach(['client:1','client:2','client:3','client:4'] as $source)salesAssert(in_array($source,$duplicates[0]['source_keys'],true),'Потеряна связь canonical client с исходным '.$source);
$sameNames=canonicalizeSalesJournalClients([['key'=>'phone:+79377171142','id'=>'1','phone'=>'+79377171142','name'=>'Рыкунов Александр Сергеевич ИП'],['key'=>'phone:+79375417002','id'=>'2','phone'=>'+79375417002','name'=>'Рыкунов Александр Сергеевич ИП']]);
salesAssert(count($sameNames)===1&&str_starts_with($sameNames[0]['key'],'name:'),'Точное имя с разными телефонами не объединено');
salesAssert(in_array('phone:+79377171142',$sameNames[0]['source_keys'],true)&&in_array('phone:+79375417002',$sameNames[0]['source_keys'],true),'Потеряны исходные телефонные ключи');
$aliasResult=loadSalesJournalBatch($sameNames,'2026-09-20','2026-09-26',static fn($method,$url,$body)=>['items'=>[['sale_id'=>900,'client_key'=>$body['clients'][0]['key'],'matched_by'=>'name','sale_date'=>'2026-09-25','document_number'=>'Р-900','client'=>'Рыкунов Александр Сергеевич ИП','phone'=>'+79370000000','manager'=>null,'department'=>'','total_amount'=>'1.00']]]);
salesAssert(($aliasResult['client_aliases']['phone:+79377171142']??'')===$sameNames[0]['key']&&($aliasResult['client_aliases']['phone:+79375417002']??'')===$sameNames[0]['key'],'Canonical aliases не возвращают продажу к обоим телефонам');
$previousStorage=getenv('CALLTRACK_STORAGE_DIR');$cacheStorage=sys_get_temp_dir().'/calltrack-sales-cache-'.bin2hex(random_bytes(4));putenv('CALLTRACK_STORAGE_DIR='.$cacheStorage);
$cacheClients=[['key'=>'client:old','phone'=>'+79990000099','name'=>'Кэш Клиент','source_keys'=>['client:old','phone:+79990000099']]];$cacheRequests=0;
$cacheTransport=static function($method,$url,$body)use(&$cacheRequests){$cacheRequests++;return ['items'=>[['sale_id'=>901,'client_key'=>$body['clients'][0]['key'],'matched_by'=>'phone','sale_date'=>'2026-09-25','document_number'=>'Р-901','client'=>'Кэш Клиент','phone'=>'+79990000099','manager'=>null,'department'=>'','total_amount'=>'10.00']]];};
$cacheMiss=loadSalesJournalBatchCached($cacheClients,'2026-09-25','2026-09-25',$cacheTransport);$cacheHit=loadSalesJournalBatchCached($cacheClients,'2026-09-25','2026-09-25',$cacheTransport);
salesAssert($cacheRequests===1&&!$cacheMiss['cache_hit']&&$cacheHit['cache_hit']&&$cacheHit['requests']===0,'Повторный запрос продаж не обслуживается быстрым кэшем');
$renamedSources=[['key'=>'client:new','phone'=>'+79990000099','name'=>'Кэш Клиент','source_keys'=>['client:new','phone:+79990000099']]];
$cacheWithCurrentAliases=loadSalesJournalBatchCached($renamedSources,'2026-09-25','2026-09-25',static function(){throw new RuntimeException('Кэш не должен обращаться к transport');});
salesAssert(($cacheWithCurrentAliases['client_aliases']['client:new']??'')==='phone:+79990000099'&&!isset($cacheWithCurrentAliases['client_aliases']['client:old']),'Cache продаж вернул устаревшие client_aliases');
$cacheFile=salesJournalCacheDirectory().'/'.salesJournalCacheKey($cacheClients,'2026-09-25','2026-09-25').'.json';touch($cacheFile,time()-SALES_JOURNAL_CACHE_TTL-1);clearstatcache(true,$cacheFile);
$stale=loadSalesJournalBatchCached($cacheClients,'2026-09-25','2026-09-25',static function(){throw new RuntimeException('timeout');});
salesAssert($stale['cache_hit']&&$stale['cache_stale']&&count($stale['items'])===1,'При ошибке Sales Journal не используется последний успешный кэш');
$emptyRequests=0;$emptyClients=[['key'=>'phone:+79990000098','phone'=>'+79990000098','name'=>'Без продаж','source_keys'=>['phone:+79990000098']]];
$emptyTransport=static function()use(&$emptyRequests){$emptyRequests++;return ['items'=>[]];};
loadSalesJournalBatchCached($emptyClients,'2026-09-25','2026-09-25',$emptyTransport);loadSalesJournalBatchCached($emptyClients,'2026-09-25','2026-09-25',$emptyTransport);
salesAssert($emptyRequests===2,'Пустой ответ Sales Journal закэширован и блокирует появление новых продаж');
$detailRequests=0;$detailTransport=static function($method,$url,$body)use(&$detailRequests){$detailRequests++;return ['id'=>141955,'document_number'=>'Р-00631068','items'=>[['id'=>1,'name'=>'Товар']]];};
$firstDetail=loadSalesJournalDetailCached(141955,$detailTransport);$cachedDetail=loadSalesJournalDetailCached(141955,$detailTransport);
salesAssert($detailRequests===1&&$firstDetail===$cachedDetail&&count($cachedDetail['items'])===1,'Detail продажи не обслуживается быстрым кэшем');
@unlink($cacheFile);@rmdir(dirname($cacheFile));@rmdir($cacheStorage.'/cache');@rmdir($cacheStorage);$previousStorage===false?putenv('CALLTRACK_STORAGE_DIR'):putenv('CALLTRACK_STORAGE_DIR='.$previousStorage);
$different=canonicalizeSalesJournalClients([['phone'=>'+79000000001','name'=>'Клиент А'],['phone'=>'+79000000002','name'=>'Клиент Б']]);salesAssert(count($different)===2,'Разные клиенты ошибочно объединены');
$similar=canonicalizeSalesJournalClients([['phone'=>'+79000000003','name'=>'Ромашка ООО'],['phone'=>'+79000000004','name'=>'Ромашка Плюс ООО']]);salesAssert(count($similar)===2,'Похожие имена ошибочно объединены');
$emptyNames=canonicalizeSalesJournalClients([['phone'=>'+79000000005','name'=>''],['phone'=>'+79000000006','name'=>'']]);salesAssert(count($emptyNames)===2,'Пустые имена объединили разные телефоны');
$normalizedNames=canonicalizeSalesJournalClients([['phone'=>'+79000000007','name'=>'Рыкунов Александр Сергеевич ИП'],['phone'=>'+79000000008','name'=>'  Рыкунов   Александр Сергеевич ИП  ']]);salesAssert(count($normalizedNames)===1,'Безопасная нормализация имени не сработала');
$ordinary=canonicalizeSalesJournalClients([['phone'=>'+79000000009','name'=>'Обычный клиент']]);salesAssert($ordinary[0]['key']==='phone:+79000000009','Обычный телефонный ключ изменился');
$withoutPhone=canonicalizeSalesJournalClients([['id'=>'7','phone'=>'','name'=>'Точное имя']]);
salesAssert($withoutPhone[0]['key']==='client:7'&&$withoutPhone[0]['name']==='Точное имя','Exact-name fallback потерял имя или стабильный ID');
salesAssert(canonicalizeSalesJournalClients([['phone'=>'','name'=>'']])===[],'Полностью пустой клиент не исключён');
$productionDuplicates=[];foreach([
    '9033161409'=>['367642','367643'],'9064015297'=>['335899','335902','335903'],'9889808336'=>['418287','418288','418289','418290'],
    '9616797416'=>['326316','326317'],'9061701434'=>['300231','300232','300233'],'9377373882'=>['327762','327763'],
] as $phone=>$ids)foreach($ids as $id)$productionDuplicates[]=['key'=>'client:'.$id,'id'=>$id,'phone'=>$phone,'name'=>'Production '.$phone];
$canonicalProduction=canonicalizeSalesJournalClients($productionDuplicates);
salesAssert(count($canonicalProduction)===6&&count(array_unique(array_column($canonicalProduction,'phone')))===6,'Production-дубли телефонов остались в payload');
salesAssert(salesJournalPeriod(['date_from'=>'2026-09-01','date_to'=>'2026-09-30'])===['2026-09-01','2026-09-30'],'Период изменён');
try{salesJournalPeriod(['date_from'=>'2026-10-01','date_to'=>'2026-09-30']);throw new RuntimeException('Обратный период принят');}catch(InvalidArgumentException $expected){}
foreach([[401,'{}',''],[500,'{}',''],[0,'','timeout']] as [$status,$raw,$error]){try{salesJournalDecodeResponse($raw,$status,$error);throw new RuntimeException("Ошибка Sales Journal {$status} принята");}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'Sales Journal'),'Ошибка скрыта неверно');}}
try{salesJournalDecodeResponse('{bad',200);throw new RuntimeException('Invalid JSON принят');}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'JSON'),'Invalid JSON не распознан');}
salesAssert(salesJournalDecodeResponse('{"items":[]}',200)===['items'=>[]],'Пустой список продаж не поддерживается');
salesAssert(salesJournalRequestUrl('https://example.test/vr/sales','/api/integrations/calltrack/client-sales')==='https://example.test/vr/sales/api/integrations/calltrack/client-sales','URL от корня Sales Journal сформирован неверно');
salesAssert(salesJournalRequestUrl('https://example.test/vr/sales/api/integrations/calltrack/','/api/integrations/calltrack/client-sales')==='https://example.test/vr/sales/api/integrations/calltrack/client-sales','Integration API path продублирован в URL');
salesAssert(salesJournalRequestUrl('https://example.test/vr/sales/api/integrations/calltrack','/api/integrations/calltrack/sales/141955')==='https://example.test/vr/sales/api/integrations/calltrack/sales/141955','Detail URL продублировал Integration API path');
try{salesJournalDecodeResponse('{"detail":"duplicate phone"}',422);throw new RuntimeException('HTTP 422 принят');}catch(RuntimeException $expected){salesAssert(str_contains($expected->getMessage(),'422'),'HTTP 422 не обрабатывается как недоступность продаж');}

$clients=[];for($i=1;$i<=501;$i++)$clients[]=['key'=>'client:'.$i,'phone'=>'+7999'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'name'=>'Клиент '.$i];
$requests=[];$transport=static function(string $method,string $url,?array $body)use(&$requests):array{$requests[]=$body;return ['items'=>[['sale_id'=>77,'client_key'=>$body['clients'][0]['key'],'matched_by'=>'phone','sale_date'=>$body['date_from'],'document_number'=>'Р-77','client'=>'Клиент','phone'=>'+79990000000','manager'=>null,'department'=>'Авиаторов','total_amount'=>'100.50']]];};
$result=loadSalesJournalBatch($clients,'2026-09-01','2026-09-30',$transport);
salesAssert(count($requests)===2&&count($requests[0]['clients'])===500&&count($requests[1]['clients'])===1,'Batch >500 не разбит последовательно');
salesAssert($result['requests']===2&&count($result['items'])===1,'sale_id не дедуплицируется между batch');
salesAssert($requests[0]['date_from']==='2026-09-01'&&$requests[0]['date_to']==='2026-09-30','Границы периода не переданы Sales Journal');
$rawForDedup=[];for($i=1;$i<=500;$i++)$rawForDedup[]=['key'=>'client:'.$i,'id'=>(string)$i,'phone'=>'+7988'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'name'=>'Клиент '.$i];$rawForDedup[]=['key'=>'client:duplicate','id'=>'duplicate','phone'=>$rawForDedup[0]['phone'],'name'=>'Клиент 1'];
$dedupRequests=[];loadSalesJournalBatch($rawForDedup,'2026-09-01','2026-09-30',static function($method,$url,$body)use(&$dedupRequests){$dedupRequests[]=$body;return ['items'=>[]];});
salesAssert(count($dedupRequests)===1&&count($dedupRequests[0]['clients'])===500,'Дедупликация выполнена после batching');

$root=dirname(__DIR__);$html=(string)file_get_contents($root.'/analizmop/index.html');$api=(string)file_get_contents($root.'/analizmop/api.js');$batch=(string)file_get_contents($root.'/api/client_sales.php');$detail=(string)file_get_contents($root.'/api/sale_detail.php');$integration=(string)file_get_contents($root.'/api/sales_journal.php');
foreach(['clientSales','saleDetail','getClientSales','getSaleDetail'] as $required)salesAssert(str_contains($api,$required),"Frontend API не содержит {$required}");
foreach(['callSegments','emailSegments','saleSegments','sortClientTimelineEvents(events)','data-sale-id','Продаж:','refreshClientSalesTimeline'] as $required)salesAssert(str_contains($html,$required),"Timeline не содержит {$required}");
foreach(['authenticatedWebUser=user', "authenticatedWebUser?.role==='manager'", 'const managers=scopedManager?[scopedManager]'] as $required)salesAssert(str_contains($html,$required),"Manager sales timeline не закреплён за авторизованным менеджером: {$required}");
foreach(['sale_id','client_key','matched_by','sale_date','document_number','total_amount'] as $required)salesAssert(str_contains($integration,$required),"Sale Summary не содержит {$required}");
foreach(['network error','HTTP ','некорректный JSON','SALES_JOURNAL_CONNECT_TIMEOUT','SALES_JOURNAL_TIMEOUT'] as $required)salesAssert(str_contains($integration,$required),"Нет обработки Sales Journal: {$required}");
salesAssert(str_contains($batch,'requireWebUser($pdo)')&&str_contains($batch,'salesJournalCommunicationClients($pdo,$user'),'Batch не применяет web scope');
salesAssert(str_contains($detail,'requireWebUser($pdo)')&&str_contains($detail,'isSalesJournalDetailAllowed'),'Detail позволяет IDOR');
salesAssert(strpos($detail,'isSalesJournalDetailAllowed')<strpos($detail,'loadSalesJournalDetailCached'),'Detail cache читается до IDOR-проверки');
salesAssert(str_contains($batch,"'available'=>false")&&str_contains($html,'Данные о продажах временно недоступны'),'Нет graceful degradation');
salesAssert(str_contains($html,"items.length?")&&str_contains($html,'Распознанные товары отсутствуют'),'Пустой items ломает карточку');
salesAssert(str_contains($html,'quantity||0)*Number(item.actual_price||0'),'Сумма товара не рассчитывается');
salesAssert(str_contains($html,'escapeHtml(item.name')&&str_contains($html,'escapeHtml(sale.document_number'),'Внешние данные выводятся без экранирования');
salesAssert(!str_contains($html,'CALLTRACK_INTEGRATION_TOKEN')&&!str_contains($api,'CALLTRACK_INTEGRATION_TOKEN'),'Bearer token попал во frontend');
salesAssert(substr_count($integration,"salesJournalRequest('POST'")===1,'Реализован N+1 вместо одного batch-цикла');
salesAssert(str_contains($integration,'array_chunk($clients,500)'),'Нет ограничения batch в 500 клиентов');
salesAssert(str_contains($integration,'manager=:manager')&&str_contains($integration,'user_phone=:user_phone'),'Manager scope не применяется к коммуникациям');
salesAssert(str_contains($html,'saleLast:1')&&str_contains($html,'a.saleLast-b.saleLast||a.time-b.time'),'Продажа не размещается последней среди событий той же даты');
salesAssert(str_contains($html,'keys.length===1?rows[0]:null')&&str_contains($html,'phone:+7${phone}'),'Frontend не связывает canonical phone key с существующей UI-группой');
salesAssert(str_contains($html,'canonicalSalesClientKey')&&str_contains($html,'clientSalesAliases=payload.client_aliases'),'Frontend не применяет canonical aliases');
salesAssert(str_contains($html,'salesByPhone')&&str_contains($html,'salesByName')&&str_contains($html,'rowPhones.flatMap'),'Плашки продаж не имеют fallback-сопоставления по телефону и имени клиента');
$config=(string)file_get_contents($root.'/api/config.php');salesAssert(str_contains($config,"SALES_JOURNAL_TIMEOUT') ?: 90")&&str_contains($config,'SALES_JOURNAL_CONNECT_TIMEOUT'),'Timeout не настраивается или снова меньше production latency');
salesAssert(str_contains($config,'SALES_JOURNAL_CACHE_TTL')&&str_contains($batch,'loadSalesJournalBatchCached'),'Быстрый кэш продаж не подключён к endpoint');
salesAssert(str_contains($config,'SALES_JOURNAL_DETAIL_CACHE_TTL')&&str_contains($detail,'loadSalesJournalDetailCached'),'Быстрый кэш карточки продажи не подключён');

echo "client_sales_timeline_test: OK\n";
