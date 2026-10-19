<?php
declare(strict_types=1);
require_once __DIR__.'/../api/sales_buyer_catalog.php';
require_once __DIR__.'/../api/client_catalog_json_stream.php';
function buyerCheck(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
$managers=[['id'=>1,'display_name'=>'One'],['id'=>2,'display_name'=>'Two']];$user=['id'=>1,'display_name'=>'One','role'=>'manager'];$admin=['id'=>3,'display_name'=>'Admin','role'=>'admin'];$calls=0;
$transport=static function($method,$path,$body)use(&$calls){$calls++;buyerCheck($path==='/api/integrations/calltrack/buyers'&&$body['manager']==='One'&&$body['page_size']===50,'authenticated manager and paging forwarded');return ['items'=>[['client_key'=>'buyer','name'=>'Buyer','phones'=>['+79892829842'],'unique_phones'=>['9892829842'],'manager'=>'One','purchase_count'=>1,'last_purchase_at'=>'2026-10-18']],'total'=>1,'page'=>1,'page_size'=>50];};
$page=salesBuyerPage($managers,$user,['user_phone'=>'web-user-2','owner'=>'Two'],$transport);
buyerCheck($calls===1&&$page['items'][0]['phones']===['9892829842'],'one bounded upstream request');
try{salesBuyerPage($managers,$user,['manager'=>'2'],$transport);throw new LogicException('foreign manager allowed');}catch(ClientCatalogAccessDenied $e){}
buyerCheck($calls===1,'forgery rejected before upstream');
$empty=salesBuyerPage($managers,$admin,[],static fn()=>['items'=>[],'total'=>0,'page'=>1,'page_size'=>50]);buyerCheck($empty['items']===[],'empty purchasers valid');
try{salesBuyerPage($managers,$admin,[],static function(){throw new RuntimeException('Sales Journal HTTP 404');});throw new LogicException('missing API accepted');}catch(SalesBuyerUpgradeRequired $e){}
foreach([json_encode([['name'=>'a"b','fields'=>['x'=>[1,2]]]]),json_encode(['id'=>['name'=>'a','fields'=>[]]])] as $json){$file=tempnam(sys_get_temp_dir(),'buyer-parser-');file_put_contents($file,$json);$rows=iterator_to_array((new ClientCatalogJsonStream($file))->rows());buyerCheck(count($rows)===1,'array/object and nested escapes');unlink($file);}
// A production-like source, generated and read without a full PHP catalogue array.
$file=tempnam(sys_get_temp_dir(),'buyer-stress-');$out=fopen($file,'wb');fwrite($out,'[');for($i=0;$i<64000;$i++)fwrite($out,($i?',':'').json_encode(['id'=>(string)$i,'name'=>'Client '.$i,'fields'=>['extra'=>str_repeat('x',650)]]));fwrite($out,']');fclose($out);
$count=0;foreach((new ClientCatalogJsonStream($file))->rows() as $row)$count++;
buyerCheck($count===64000&&memory_get_peak_usage(true)<32*1024*1024,'bounded legacy parser under 32MiB');
echo 'sales_buyer_catalog: scope/search/paging contract, empty, missing API, parser, stress bytes='.filesize($file).' rows='.$count.' peak_php_bytes='.memory_get_peak_usage(true).PHP_EOL;unlink($file);
