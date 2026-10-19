<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_category_details.php';
function categoryCheck(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
putenv('CALLTRACK_STORAGE_DIR='.sys_get_temp_dir().'/category-unit-'.getmypid());
$context=['directory_direct'=>true,'directory_name_unique'=>true,'phone'=>'','client_name'=>'One','requested_name'=>'One','matches'=>[
 ['client_id'=>'one','name'=>'One'],['client_id'=>'two','name'=>'Two']],'client_keys'=>['client:one','client:two']];
$calls=0;$transport=static function($method,$path,$request)use(&$calls){$calls++;categoryCheck($request['names']===['One','Two'],'both active tags in one batch');return ['category'=>$request['category'],'subcategory'=>$request['subcategory'],'level'=>$request['subcategory']===null?'subcategories':'products','items'=>[['key'=>'x','name'=>'Sub','revenue'=>'10.005','quantity'=>'2']],'parent_revenue'=>'10.005','parent_quantity'=>'2','total_count'=>1];};
$data=clientCategoryDetails($context,['category'=>'Category'],$transport);categoryCheck($data['items'][0]['revenue']==='10.005'&&$calls===1,'exact decimals');
clientCategoryDetails($context,['category'=>'Category'],$transport);categoryCheck($calls===1,'repeated path cached');
clientCategoryDetails($context,['category'=>'Category','subcategory'=>'Sub'],$transport);categoryCheck($calls===2,'distinct child path');
$forged=$context;$forged['client_keys']=['client:foreign'];try{clientCategoryDetails($forged,['category'=>'Category'],$transport);throw new LogicException('foreign tag accepted');}catch(InvalidArgumentException $e){}
categoryCheck($calls===2,'forged tags rejected before remote request');
$empty=$context;$empty['client_keys']=[];categoryCheck(clientCategoryDetails($empty,['category'=>'Category'],$transport)['items']===[]&&$calls===2,'no selected tags means no request');
try{clientCategoryDetails($context,['category'=>[]],$transport);throw new LogicException('invalid path accepted');}catch(InvalidArgumentException $e){}
$failed=static function(){throw new RuntimeException('offline');};
for($i=0;$i<2;$i++)try{clientCategoryDetails($context,['category'=>'Offline'],$failed);throw new LogicException('error ignored');}catch(RuntimeException $e){categoryCheck($e->getMessage()==='offline','upstream error propagated');}
$badCalls=0;$invalid=static function()use(&$badCalls){$badCalls++;return ['level'=>'subcategories','category'=>'Bad','subcategory'=>null,'items'=>array_fill(0,11,['name'=>'bad','revenue'=>'1','quantity'=>'1']),'total_count'=>11,'parent_revenue'=>'11','parent_quantity'=>'11'];};
for($i=0;$i<2;$i++)try{clientCategoryDetails($context,['category'=>'Bad'],$invalid);throw new LogicException('invalid response accepted');}catch(RuntimeException $e){}
categoryCheck($badCalls===2,'invalid response not cached as success');
foreach(glob(getenv('CALLTRACK_STORAGE_DIR').'/cache/client-catalog/*')?:[] as $file)unlink($file);
rmdir(getenv('CALLTRACK_STORAGE_DIR').'/cache/client-catalog');rmdir(getenv('CALLTRACK_STORAGE_DIR').'/cache');rmdir(getenv('CALLTRACK_STORAGE_DIR'));
echo "client_category_details: authorized multi-tag batch, decimals, cache, empty selection, forged tags, path validation and upstream errors OK\n";
