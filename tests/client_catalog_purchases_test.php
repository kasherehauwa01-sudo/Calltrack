<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_catalog_purchases.php';
function purchaseCheck(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
$records=[];for($i=0;$i<201;$i++)$records['c'.$i]=['name'=>'Client '.$i];
$calls=0;$transport=static function($method,$path,$body)use(&$calls){
    $calls++;purchaseCheck($body['date_from']==='0001-01-01'&&$body['date_to']==='9999-12-31','all-time');$items=[];
    foreach($body['clients'] as $client){purchaseCheck($client['phone']==='','same name-only history contract');
        if($client['name']==='Client 200')continue;
        foreach(['2026-01-01','2026-10-18'] as $i=>$date)$items[]=['sale_id'=>crc32($client['key'])+$i,'client_key'=>$client['key'],'sale_date'=>$date,'client'=>$client['name'],'total_amount'=>'1.00'];
    }return ['items'=>$items];
};
$dates=clientCatalogPurchaseDates($records,$transport);
purchaseCheck($calls===3,'201 clients use 3 batch requests, not 201');
purchaseCheck($dates['c0']==='2026-10-18'&&$dates['c199']==='2026-10-18'&&$dates['c200']===null,'latest and no purchases');
try{clientCatalogPurchaseDates($records,static function(){throw new RuntimeException('offline');});throw new LogicException('error ignored');}catch(RuntimeException $e){purchaseCheck($e->getMessage()==='offline','failure preserved');}
$bad=static function($method,$path,$body){$c=$body['clients'][0];return ['items'=>[['sale_id'=>1,'client_key'=>$c['key'],'client'=>'Other client','sale_date'=>'2026-10-18']]];};
try{clientCatalogPurchaseDates(['a'=>['name'=>'Client']],$bad);throw new LogicException('mismatched history accepted');}catch(RuntimeException $e){purchaseCheck($e->getMessage()==='Несогласованная история покупок','wrong client rejected');}
$dir=sys_get_temp_dir().'/catalog-cache-test-'.getmypid();$builds=0;
$build=static function()use(&$builds){$builds++;return [];};
clientCatalogCachedArray('one',$build,60,$dir);clientCatalogCachedArray('one',$build,60,$dir);
purchaseCheck($builds===1,'empty success cached');clientCatalogCachedArray('two',$build,60,$dir);purchaseCheck($builds===2,'revision/scope invalidation');
foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);
echo "client_catalog_purchases: batching, full history latest dates, no purchases, failure, empty cache, invalidation OK\n";
