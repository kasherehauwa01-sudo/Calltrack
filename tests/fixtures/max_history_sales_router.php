<?php
// The existing workspace fixture deliberately rejects phone+name sales queries.
// This local stub also supports the existing main timeline's legitimate phone+name contract.
if(getenv('CALLTRACK_TEST_FIXTURES')!=='1'){http_response_code(404);exit;}
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer test-only-token'){http_response_code(401);exit;}
$data=json_decode(file_get_contents('php://input'),true);
if(str_ends_with($_SERVER['REQUEST_URI'],'/client-category-details')){
    $product=isset($data['subcategory']);$items=[];for($i=0;$i<($product?20:10);$i++)$items[]=['key'=>(string)$i,'name'=>($product?'Товар ':'Подкатегория ').$i,'revenue'=>(string)(100-$i).'.00','quantity'=>'2','article'=>$product?'A'.$i:null,'code'=>$product?'C'.$i:null];
    header('Content-Type: application/json');echo json_encode(['level'=>$product?'products':'subcategories','category'=>$data['category'],'subcategory'=>$data['subcategory']??null,'items'=>$items,'total_count'=>$product?25:12,'parent_revenue'=>'1000.00','parent_quantity'=>'50']);return;
}
if(str_ends_with($_SERVER['REQUEST_URI'],'/buyers')){
    $rows=[];
    foreach([['Покупатель','9992223344','One','2026-10-01'],['Инесса Кораблева','9892829842','One','2026-10-01'],['Альфа Каталог','9000000001','One','2026-10-18'],['Бета Каталог','9000000002','Two','2026-10-19'],['Без звонков','9000000003','One','2026-10-16'],['Старый клиент','9000000004','One','2026-09-01']] as [$name,$phone,$owner,$day])$rows[]=['name'=>$name,'phones'=>[$phone],'manager'=>$owner,'last_purchase_at'=>$day];
    for($i=0;$i<100;$i++)$rows[]=['name'=>sprintf('Клиент каталога %03d',$i),'phones'=>[(string)(9000100000+$i)],'manager'=>$i<60?'One':'Two','last_purchase_at'=>'2026-01-01'];
    $rows=array_values(array_filter($rows,static function($r)use($data){
        if(($data['manager']??'')!==''&&$data['manager']!==null&&$data['manager']!==$r['manager'])return false;
        if(isset($data['client_key'])&&$data['client_key']!==mb_strtolower($r['name']))return false;
        $search=trim((string)($data['search']??''));$digits=preg_replace('/\D/','',$search);if(strlen($digits)>=10)$digits=substr($digits,-10);
        if($search!==''&&!str_contains(mb_strtolower($r['name']),mb_strtolower($search))&&($digits===''||!str_contains($r['phones'][0],$digits)))return false;
        return true;
    }));
    usort($rows,static fn($a,$b)=>strcmp($b['last_purchase_at'],$a['last_purchase_at'])?:strcmp(mb_strtolower($a['name']),mb_strtolower($b['name'])));
    $total=count($rows);$size=(int)($data['page_size']??50);$page=min((int)($data['page']??1),max(1,(int)ceil($total/$size)));
    $rows=array_slice($rows,($page-1)*$size,$size);foreach($rows as &$r){$r['client_key']=mb_strtolower($r['name']);$r['purchase_count']=1;$r['unique_phones']=$r['phones'];}unset($r);
    header('Content-Type: application/json');echo json_encode(['items'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>$size]);return;
}
if(str_ends_with($_SERVER['REQUEST_URI'],'/client-sales') && !empty($data['clients'][0]['phone'])){
    header('Content-Type: application/json');$items=[];
    foreach($data['clients'] as $i=>$client)$items[]=['sale_id'=>900+$i,'client_key'=>$client['key'],'matched_by'=>'phone','sale_date'=>'2026-10-17','document_number'=>'TEST-900','client'=>$client['name'],'phone'=>$client['phone'],'manager'=>'One','department'=>'Test','total_amount'=>'20.00'];
    echo json_encode(['items'=>$items]);return;
}
if(str_ends_with($_SERVER['REQUEST_URI'],'/client-sales') && (count($data['clients']??[])>1 || in_array($data['clients'][0]['name']??'',['Альфа Каталог','Бета Каталог','Без звонков','Старый клиент']))){
    header('Content-Type: application/json');$items=[];
    $dates=['Альфа Каталог'=>'2026-10-18','Бета Каталог'=>'2026-10-19','Без звонков'=>'2026-10-16','Старый клиент'=>'2026-09-01','Покупатель'=>'2026-10-01','Инесса Кораблева'=>'2026-10-01'];
    foreach($data['clients'] as $client){if(!isset($dates[$client['name']]))continue;
        $items[]=['sale_id'=>10000+(int)sprintf('%u',crc32($client['key'])),'client_key'=>$client['key'],'matched_by'=>'name','sale_date'=>$dates[$client['name']],'document_number'=>'TEST','client'=>$client['name'],'phone'=>'','manager'=>'One','department'=>'Test','total_amount'=>'20.00'];
    }
    echo json_encode(['items'=>$items]);return;
}
require __DIR__.'/client-workspace/sales-router.php';
