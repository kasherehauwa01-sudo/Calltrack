<?php
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'){http_response_code(404);exit;}
header('Content-Type: application/json');
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer test-only-token'){http_response_code(401);echo '{}';return;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $data=json_decode(file_get_contents('php://input'),true);$items=[];
 foreach($data['clients'] as $client){if($client['name']!=='')throw new RuntimeException('Unsafe name matching');
 $phone=$client['phone'];$prefix=$phone==='+79992223355'?200:100;
 foreach([['2024-02-29','3.01'],['2025-11-01','20.20'],['2026-10-01','0.10'],['2026-10-01','0.20']] as $i=>[$date,$amount])$items[]=['sale_id'=>$prefix+$i,'client_key'=>$client['key'],'matched_by'=>'phone','sale_date'=>$date,'document_number'=>'РН-'.($prefix+$i),'client'=>'Test buyer','phone'=>$phone,'manager'=>'One','department'=>'Test','total_amount'=>$amount];
 }
 echo json_encode(['items'=>$items]);
}else{
 $id=(int)basename($_SERVER['REQUEST_URI']);echo json_encode(['id'=>$id,'sale_date'=>'2026-10-01','document_number'=>'РН-'.$id,'client'=>'Test buyer','phone'=>'+79992223344','department'=>'Test','manager'=>'One','total_amount'=>'0.10','base_amount'=>'0.10','items'=>[['article'=>'A','code'=>'B','name'=>'Test product','quantity'=>'1.000','actual_price'=>'0.10','base_price'=>'0.10']]]);
}
