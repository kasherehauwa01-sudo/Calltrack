<?php
declare(strict_types=1);
require_once __DIR__.'/../api/purchase_analytics.php';
$checks=0;
function check($actual,$expected,string $message): void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($message.': '.var_export($actual,true));}
function sale(int $id,string $date,string $amount):array{return ['sale_id'=>$id,'sale_date'=>$date,'document_number'=>'РН-'.$id,'total_amount'=>$amount];}
$defaults=purchaseDefaultPeriod(new DateTimeImmutable('2026-10-15'));
check($defaults,['from'=>'2025-11','to'=>'2026-10','detail'=>'month'],'12 calendar months');
check(purchaseDefaultPeriod(new DateTimeImmutable('2026-01-31'))['from'],'2025-02','January boundary');
$sales=purchaseDataset([sale(1,'2025-11-01','100.10'),sale(2,'2025-11-01','200.20'),sale(3,'2026-01-10','5.01'),sale(4,'2024-11-01','200.00'),sale(5,'2025-01-10','10.00'),sale(6,'2020-01-01','0.01')]);
$a=purchaseAnalytics($sales,'2025-11','2026-10','month');
check(count($a['buckets']),12,'12 buckets');check($a['previous_from'],'2024-11-01','previous start');check($a['previous_to'],'2025-10-31','previous end');
check($a['current_total'],'305.31','current total');check($a['previous_total'],'210.00','previous total');check($a['absolute_change'],'95.31','absolute change');check($a['percentage_change'],45.4,'percentage');
check($a['buckets'][0]['current'],'300.30','monthly sum');check($a['buckets'][1]['current'],'0.00','empty current');check($a['buckets'][1]['previous'],'0.00','empty previous');check($a['buckets'][1]['percentage_change'],null,'no division by zero');
check($a['buckets'][2]['absolute_change'],'-4.99','negative difference');check($a['buckets'][2]['percentage_change'],-49.9,'negative percentage');
$tree=purchaseTree($sales);check(array_column($tree,'key'),['2026','2025','2024','2020'],'year ordering / whole history');
check($tree[1]['months'][0]['total_amount'],'300.30','month total');check($tree[1]['months'][0]['dates'][0]['total_amount'],'300.30','date total');check(array_column($tree[1]['months'][0]['dates'][0]['sales'],'sale_id'),[1,2],'stable same day IDs');check($tree[1]['total_amount'],'310.30','year total');
foreach(['day','month','year'] as $detail){$a=purchaseAnalytics($sales,'2025-11','2026-10',$detail);check($a['current_total'],'305.31','same data current '.$detail);check($a['previous_total'],'210.00','same data previous '.$detail);$sum=0;$prev=0;foreach($a['buckets'] as $b){$sum=purchaseAdd($sum,purchaseCents($b['current']));$prev=purchaseAdd($prev,purchaseCents($b['previous']));}check(purchaseMoney($sum),$a['current_total'],'sum buckets current '.$detail);check(purchaseMoney($prev),$a['previous_total'],'sum buckets previous '.$detail);}
$leap=purchaseDataset([sale(10,'2024-02-29','2.99'),sale(11,'2024-03-01','3.01'),sale(12,'2023-03-01','1.01'),sale(13,'2025-03-01','4.01')]);
foreach([['2024-02','2024-03','2.99','0.00','2024-03-01','2023-03-01'],['2025-02','2025-03','0.00','2.99','2025-03-01','2024-03-01']] as [$from,$to,$c,$p,$march,$previousMarch]){
 $a=purchaseAnalytics($leap,$from,$to,'day');check(count($a['buckets']),60,'leap union size');check($a['buckets'][28]['current'],$c,'Feb29 current');check($a['buckets'][28]['previous'],$p,'Feb29 previous');check($a['buckets'][29]['current_date'],$march,'March aligned');check($a['buckets'][29]['previous_date'],$previousMarch,'previous March aligned');
}
check(purchaseAnalytics($leap,'2025-02','2025-03','day')['previous_total'],'6.00','leap day not lost');
check(purchaseAnalytics([],'2026-01','2026-12','month')['percentage_change'],null,'empty history delta');check(purchaseTree([]),[],'empty tree');
check(purchaseMoney(purchaseCents('9999999999999999.99')),'9999999999999999.99','Numeric(18,2) precision');
check(purchaseDelta(100,0)['absolute_change'],'1.00','zero baseline absolute');
foreach([['2026-02','2026-01','month'],['2026-13','2026-01','month'],['2023-01','2026-01','day'],['2026-01','2026-01','week']] as $args){try{purchaseAnalytics([],...$args);throw new RuntimeException('Invalid range accepted');}catch(InvalidArgumentException $e){$checks++;}}
check(count(purchaseDataset([sale(1,'2026-01-01','1.00'),sale(1,'2026-01-01','1.00')])),1,'dedupe IDs');
echo "purchase_analytics_test: $checks checks OK\n";
