<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_insights.php';
$checks=0;
function checkMetric($actual,$expected,string $label): void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true));}
$sales=purchaseDataset([
 ['sale_id'=>1,'sale_date'=>'2026-01-01','total_amount'=>'10.00','quantity'=>'1.250'],
 ['sale_id'=>2,'sale_date'=>'2026-01-01','total_amount'=>'20.00','quantity'=>'2.750'],
 ['sale_id'=>3,'sale_date'=>'2026-02-01','total_amount'=>'90.00','quantity'=>'8.000'],
 ['sale_id'=>4,'sale_date'=>'2025-01-01','total_amount'=>'8.00','quantity'=>'4.000'],
]);
foreach(['revenue'=>['120.00','8.00'],'sales_count'=>['3','1'],'average_check'=>['40.00','8.00'],'units'=>['12.000','4.000'],'units_per_sale'=>['4.000','4.000']] as $metric=>[$current,$previous]){
 foreach(['day','month','year'] as $detail){
  $a=purchaseAnalytics($sales,'2026-01','2026-02',$detail,$metric);
  checkMetric($a['current_total'],$current,$metric.' weighted total '.$detail);
  checkMetric($a['previous_total'],$previous,$metric.' comparison '.$detail);
 }
 checkMetric(purchaseAnalytics([],'2026-01','2026-02','month',$metric)['percentage_change'],null,'no comparison base');
}
$a=purchaseAnalytics($sales,'2026-01','2026-02','month','average_check');
checkMetric(array_column($a['buckets'],'current'),['15.00','90.00'],'bucket averages; total is not average of bucket averages');
checkMetric(purchaseAverage(9007199254740993,1),9007199254740993,'no float rounding for large monetary values');
checkMetric(purchaseAverage(-3,2),-2,'negative half rounds away from zero');
$summary=purchaseLifetime($sales,new DateTimeImmutable('2026-02-08',new DateTimeZone('Europe/Moscow')));
checkMetric($summary['sales_count'],4,'lifetime not chart period');
checkMetric($summary['revenue'],'128.00','lifetime revenue');
checkMetric($summary['first_purchase'],'2025-01-01','first purchase');
checkMetric($summary['last_purchase'],'2026-02-01','last purchase');
checkMetric($summary['average_interval'],198.0,'interval between distinct purchase dates');
checkMetric($summary['days_without_purchase'],7,'calendar days since purchase');
checkMetric(purchaseLifetime([])['first_purchase'],null,'empty first date');
checkMetric(purchaseLifetime([$sales[0]])['average_interval'],null,'one date has no interval');
try{purchaseAnalytics($sales,'2026-01','2026-02','month','invalid');throw new RuntimeException('invalid metric accepted');}catch(InvalidArgumentException $e){$checks++;}
try{purchaseAttachQuantities($sales,['sale_quantities'=>[]]);throw new RuntimeException('missing item stats treated as zero');}catch(RuntimeException $e){checkMetric(str_contains($e->getMessage(),'Состав продаж'),true,'missing item stats explicit failure');}
echo "purchase_metrics_test: $checks checks OK\n";
