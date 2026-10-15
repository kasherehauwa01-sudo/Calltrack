<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_insights.php';
$checks=0;
function snapshotCheck($actual,$expected,string $label): void {
    global $checks;$checks++;
    if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true));
}
$legacy=['sale_quantities'=>[['sale_id'=>1,'quantity'=>'2.000']],'unique_products'=>1,'top_categories'=>[],
    'rfm'=>['segment'=>'Постоянные активные','code'=>'545']];
$normalized=clientHistoryNormalizeInsights($legacy);
snapshotCheck($normalized['rfm'],$legacy['rfm'],'legacy response');
snapshotCheck($normalized['rfm_status'],'available','legacy RFM available');
foreach(['unavailable','snapshot_missing','client_missing','stale'] as $status){
    $input=$legacy;$input['rfm_status']=$status;
    if($status!=='stale')$input['rfm']=null;
    $output=clientHistoryNormalizeInsights($input);
    snapshotCheck($output['rfm_status'],$status,'controlled '.$status);
    snapshotCheck($output['unique_products'],1,'products survive '.$status);
}
$missing=$legacy;unset($missing['rfm']);
snapshotCheck(clientHistoryNormalizeInsights($missing)['rfm'],null,'missing RFM optional');
foreach([false,true] as $nested){
    $input=$legacy;$metadata=['calculated_at'=>'2026-10-15T06:00:00+03:00','period_from'=>'2025-10-15','period_to'=>'2026-10-15','snapshot_id'=>'internal-only'];
    if($nested)$input['rfm']+=$metadata;else $input+=$metadata;
    $output=clientHistoryNormalizeInsights($input);
    foreach(['calculated_at','period_from','period_to'] as $key)snapshotCheck($output[$key],$metadata[$key],'metadata '.$key);
    snapshotCheck(str_contains(json_encode($output),'internal-only'),false,'snapshot ID withheld');
}
foreach([['rfm'=>'bad'],['rfm'=>['segment'=>[]]],['sale_quantities'=>'bad'],['unique_products'=>-1],['top_categories'=>[['category'=>[]]]]] as $bad){
    try{clientHistoryNormalizeInsights(array_replace($legacy,$bad));throw new LogicException('malformed accepted');}
    catch(RuntimeException $e){$checks++;}
}
$temp=sys_get_temp_dir().'/calltrack-snapshot-'.bin2hex(random_bytes(6));
putenv('CALLTRACK_STORAGE_DIR='.$temp);putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
$context=['phone'=>'','matches'=>[],'client_name'=>'Allowed client'];$requests=0;
$transport=static function($method,$path,$body)use(&$requests,$legacy){
    $requests++;
    snapshotCheck($method,'POST','S2S method');
    snapshotCheck($path,'/api/integrations/calltrack/client-insights','no rebuild endpoint');
    snapshotCheck(array_keys($body),['name','as_of'],'only legacy read contract');
    snapshotCheck($body['name'],'Allowed client','authorized identity');
    return $legacy;
};
clientHistoryInsights($context,$transport);clientHistoryInsights($context,$transport);
snapshotCheck($requests,2,'no persistent insights/RFM cache');
snapshotCheck(is_dir($temp),false,'no storage written');
foreach(['timeout','HTTP 503'] as $error){
    try{clientHistoryInsights($context,static function()use($error){throw new RuntimeException($error);});throw new LogicException('failure swallowed');}
    catch(RuntimeException $e){snapshotCheck($e->getMessage(),$error,'no stale local RFM fallback');}
}
snapshotCheck(purchaseTree(purchaseDataset([['sale_id'=>1,'sale_date'=>'2026-10-15','total_amount'=>'0.30']]))[0]['total_amount'],'0.30','purchases independent of RFM');
echo "client_insights_snapshot_test: $checks checks OK\n";
