<?php
// The existing workspace fixture deliberately rejects phone+name sales queries.
// This local stub also supports the existing main timeline's legitimate phone+name contract.
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'){http_response_code(404);exit;}
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer test-only-token'){http_response_code(401);exit;}
$data=json_decode(file_get_contents('php://input'),true);
if(str_ends_with($_SERVER['REQUEST_URI'],'/client-sales') && !empty($data['clients'][0]['phone'])){
    header('Content-Type: application/json');$items=[];
    foreach($data['clients'] as $i=>$client)$items[]=['sale_id'=>900+$i,'client_key'=>$client['key'],'matched_by'=>'phone','sale_date'=>'2026-10-17','document_number'=>'TEST-900','client'=>$client['name'],'phone'=>$client['phone'],'manager'=>'One','department'=>'Test','total_amount'=>'20.00'];
    echo json_encode(['items'=>$items]);return;
}
require __DIR__.'/client-workspace/sales-router.php';
