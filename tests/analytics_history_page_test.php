<?php
declare(strict_types=1);
require_once __DIR__.'/../api/analytics_history_page.php';
function checkHistory(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function rejectHistory(callable $fn,string $message):void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Accepted invalid: '.$message);}
$base=['version'=>1,'normalization_version'=>1,'department'=>'Авиаторов','client_key'=>'тест клиент','history_hash'=>str_repeat('a',64),'sale_count'=>3];
$first=$base+['items'=>[['sale_id'=>10,'department'=>'Авиаторов'],['sale_id'=>20,'department'=>'Авиаторов']],'next_after'=>20];
$p=['history_hash'=>$base['history_hash'],'after'=>0,'seen'=>0,'complete'=>false];
$p=analyticsHistoryAdvancePage($base,$first,$p,0);
checkHistory($p['seen']===2&&$p['after']===20&&!$p['complete'],'valid first page');
$last=$base+['items'=>[['sale_id'=>30,'department'=>'Авиаторов']],'next_after'=>null];
$p=analyticsHistoryAdvancePage($base,$last,$p,20);
checkHistory($p['seen']===3&&$p['complete'],'valid final page');
rejectHistory(fn()=>analyticsHistoryAdvancePage($base,$last,$p,20),'repeat after completion');
$p=['history_hash'=>$base['history_hash'],'after'=>0,'seen'=>0,'complete'=>false];
$variants=[
 'changed hash'=>['history_hash'=>str_repeat('b',64)],
 'count'=>['sale_count'=>4],
 'identity'=>['client_key'=>'another'],
 'department'=>['department'=>'Не Авиаторов'],
 'missing cursor'=>['next_after'=>'missing'],
 'wrong cursor'=>['next_after'=>11],
 'unclosed at exact count'=>['sale_count'=>2],
 'bad id'=>['items'=>[['sale_id'=>10,'department'=>'Авиаторов'],['sale_id'=>10,'department'=>'Авиаторов']]],
 'wrong group'=>['items'=>[['sale_id'=>10,'department'=>'Чужой']]],
 'empty partial'=>['items'=>[],'next_after'=>null],
];
foreach($variants as $name=>$overrides){
 $snapshot=$base;$page=array_replace($first,$overrides);
 if($name==='unclosed at exact count')$snapshot['sale_count']=2;
 rejectHistory(fn()=>analyticsHistoryAdvancePage($snapshot,$page,$p,0),$name);
}
rejectHistory(fn()=>analyticsHistoryAdvancePage($base,$first,['history_hash'=>$base['history_hash'],'after'=>50,'seen'=>0,'complete'=>false],0),'invalid session cursor');
echo "analytics_history_page_test: all cases passed\n";
