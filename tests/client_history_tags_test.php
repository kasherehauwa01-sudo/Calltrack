<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_insights.php';
$checks=0;
function tagCheck($actual,$expected,string $label): void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true));}
$context=['phone'=>'','matches'=>[['client_id'=>'a','name'=>'Альфа'],['client_id'=>'b','name'=>'Бета'],['client_id'=>'a2','name'=>'Альфа']], 'client_keys'=>['client:a','client:b','client:a2']];
tagCheck(array_column(clientHistorySelectedClients($context),'name'),['Альфа','Бета'],'namesakes deduplicated');
$none=$context;$none['client_keys']=[];
tagCheck(clientHistorySales($none,static fn()=>throw new RuntimeException('Unexpected upstream call'))['items'],[],'no tags means no sales or upstream call');
tagCheck(clientHistoryInsights($none)['unique_products'],0,'empty product count');
$bad=$context;$bad['client_keys']=['client:foreign'];
try{clientHistorySelectedClients($bad);throw new RuntimeException('forged tag accepted');}catch(InvalidArgumentException $e){$checks++;}
$part=['unique_products'=>2,'rfm'=>['segment'=>'Активный'],'sale_quantities'=>[['sale_id'=>1,'quantity'=>'2.000']],'top_categories'=>[['category'=>'Сад','revenue'=>'1.00500','quantity'=>'1.250']]];
$other=$part;$other['top_categories'][0]['revenue']='2.00500';$other['cache_stale']=true;
$merged=clientHistoryMergeInsights(['Альфа'=>$part,'Бета'=>$other]);
tagCheck($merged['top_categories'][0]['revenue'],'3.010000','sum before display rounding');
tagCheck($merged['top_categories'][0]['quantity'],'2.500000','exact quantities');
tagCheck(count($merged['sale_quantities']),1,'shared sale quantities not doubled');
tagCheck($merged['unique_products'],null,'overlapping unique counts are not summed');
tagCheck(count($merged['unique_products_by_client']),2,'individual unique counts retained');
tagCheck(count($merged['rfm_by_client']),2,'individual RFM retained');
tagCheck($merged['cache_stale'],true,'stale propagated');
$other['top_categories']=[];
for($i=0;$i<20;$i++)$other['top_categories'][]=['category'=>$i===0?'Сад':'Категория '.$i,'revenue'=>(string)(20-$i).'.00500','quantity'=>'1.250'];
$truncated=clientHistoryMergeInsights(['Альфа'=>$part,'Бета'=>$other]);
tagCheck(count($truncated['top_categories']),20,'truncated source still displays merged top-20');
tagCheck($truncated['top_categories'][0]['category'],'Сад','shared category merged and sorted');
tagCheck($truncated['top_categories'][0]['revenue'],'21.010000','available shared revenue summed exactly');
tagCheck($truncated['top_categories'][0]['quantity'],'2.500000','available shared quantity summed exactly');
tagCheck($truncated['categories_complete'],false,'truncated data explicitly marked');
tagCheck($truncated['notice']!=='',true,'truncated data explained');
tagCheck($merged['categories_complete'],true,'short complete source lists stay complete');
$other['categories_complete']=true;
tagCheck(clientHistoryMergeInsights(['Альфа'=>$part,'Бета'=>$other])['categories_complete'],true,'explicit full list accepted');
$part['categories_complete']=false;
tagCheck(clientHistoryMergeInsights(['Альфа'=>$part])['categories_complete'],false,'explicit truncation honored even for short lists');
tagCheck(clientHistoryMergeInsights([])['top_categories'],[],'no active clients gives an empty category list');
$temp=sys_get_temp_dir().'/calltrack-tags-'.bin2hex(random_bytes(8));$old=getenv('CALLTRACK_STORAGE_DIR');putenv('CALLTRACK_STORAGE_DIR='.$temp);
try{
    $transport=static function($method,$path,$body){
        $items=[];foreach($body['clients'] as $i=>$client){$items[]=['sale_id'=>$i+1,'client_key'=>$client['key'],'client'=>$client['name'],'sale_date'=>'2026-10-01','total_amount'=>($i+1).'.00'];$items[]=end($items);}
        return ['items'=>$items];
    };
    $sales=clientHistorySales($context,$transport);
    tagCheck(count($sales['items']),2,'batch sale IDs deduplicated');
    tagCheck(purchaseLifetime(purchaseDataset($sales['items']))['revenue'],'3.00','combined lifetime revenue');
}finally{
    putenv($old===false?'CALLTRACK_STORAGE_DIR':'CALLTRACK_STORAGE_DIR='.$old);
    if(is_dir($temp)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($temp);}
}
echo "client_history_tags_test: $checks checks OK\n";
