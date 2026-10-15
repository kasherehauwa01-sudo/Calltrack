<?php
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'){http_response_code(404);exit;}
header('Content-Type: application/json');
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer test-only-token'){http_response_code(401);echo '{}';return;}
if(str_ends_with($_SERVER['REQUEST_URI'],'/client-insights')){
 $modeFile=getenv('CALLTRACK_TEST_INSIGHTS_MODE_FILE');
 $mode=$modeFile && is_file($modeFile)?trim(file_get_contents($modeFile)):'';
 if($mode==='http503'){http_response_code(503);echo '{}';return;}
 if($mode==='malformed'){echo '{invalid';return;}
 if($mode==='missing'){echo json_encode(['sale_quantities'=>[],'unique_products'=>0,'top_categories'=>[],'rfm'=>null,'rfm_status'=>'snapshot_missing']);return;}

 $quantities=[];foreach([100,200] as $prefix)foreach([1,2,3,4] as $i=>$quantity)$quantities[]=['sale_id'=>$prefix+$i,'quantity'=>(string)$quantity];
 echo json_encode(['calculated_at'=>'2026-10-15T06:00:00+03:00','period_from'=>'2025-10-15','period_to'=>'2026-10-15','snapshot_id'=>'test-internal-snapshot','unique_products'=>3,'top_categories'=>[['category'=>'Посуда','revenue'=>'40.00','quantity'=>'12.000'],['category'=>'Упаковка','revenue'=>'7.02','quantity'=>'8.000']],'uncategorized_products'=>0,'rfm'=>['segment'=>'Постоянные активные','code'=>'555','tags'=>[]],'sale_quantities'=>$quantities]);return;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $data=json_decode(file_get_contents('php://input'),true);$items=[];
 foreach($data['clients'] as $client){if($client['name']!=='' && $client['phone']!=='')throw new RuntimeException('Name query must omit phone');
 $phone=$client['phone'];foreach(($client['name']==='Покупатель'?[100,200]:[$phone==='+79992223355'?200:100]) as $prefix){
 foreach([['2024-02-29','3.01'],['2025-11-01','20.20'],['2026-10-01','0.10'],['2026-10-01','0.20']] as $i=>[$date,$amount])$items[]=['sale_id'=>$prefix+$i,'client_key'=>$client['key'],'matched_by'=>'phone','sale_date'=>$date,'document_number'=>'РН-'.($prefix+$i),'client'=>$client['name']?:'Test buyer','phone'=>$phone,'manager'=>'One','department'=>'Test','total_amount'=>$amount];
 }}
 echo json_encode(['items'=>$items]);
}else{
 $id=(int)basename($_SERVER['REQUEST_URI']);echo json_encode(['id'=>$id,'sale_date'=>'2026-10-01','document_number'=>'РН-'.$id,'client'=>'Test buyer','phone'=>'+79992223344','department'=>'Test','manager'=>'One','total_amount'=>'0.10','base_amount'=>'0.10','items'=>[['article'=>'A','code'=>'B','name'=>'Test product','quantity'=>'1.000','actual_price'=>'0.10','base_price'=>'0.10']]]);
}
