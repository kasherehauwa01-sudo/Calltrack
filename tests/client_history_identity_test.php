<?php
declare(strict_types=1);
require_once __DIR__.'/../api/client_history_context.php';
require_once __DIR__.'/../api/purchase_analytics.php';
$checks=0;
function identityCheck($actual,$expected,string $label): void {
    global $checks;$checks++;
    if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true));
}
function directoryRow(string $id,string $name,string $phones): array {
    return ['id'=>$id,'Наименование'=>$name,'Телефоны'=>$phones];
}
$temp=sys_get_temp_dir().'/calltrack-identity-'.bin2hex(random_bytes(8));
$oldStorage=getenv('CALLTRACK_STORAGE_DIR');$oldSqlite=getenv('CALLTRACK_CLIENTS_DISABLE_SQLITE');
putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
function scenario(string $name,array $rows): void {
    global $temp;putenv('CALLTRACK_STORAGE_DIR='.$temp.'/'.$name);
    writeClientsCache(normalizeClientsPayload($rows));
}
$requests=0;
$transport=static function($method,$path,$body)use(&$requests){
    $requests++;$client=$body['clients'][0];
    identityCheck($client['phone'],'','phone must not constrain sales');
    identityCheck($client['name'],'Харченко Николай Николаевич ИП','exact directory name sent');
    $items=[];
    foreach([[101,'+79000000001','Харченко Николай Николаевич ИП','10.10'],
             [102,'+79000000009','  ХАРЧЕНКО  Николай Николаевич ИП ','20.20'],
             [103,'','Харченко Николай Николаевич ИП','1.01'],
             [104,'+79000000001','Другой клиент','90.00']] as [$id,$phone,$name,$amount]){
        $items[]=['sale_id'=>$id,'client_key'=>$client['key'],'matched_by'=>'name','sale_date'=>'2026-10-01','document_number'=>'РН-'.$id,'phone'=>$phone,'client'=>$name,'total_amount'=>$amount];
    }
    return ['items'=>$items];
};
try {
    $name='Харченко Николай Николаевич ИП';
    scenario('shared',[directoryRow('a',$name,'+79000000001'),directoryRow('a',$name,'+79000000001'),directoryRow('b','Другой клиент','+79000000001')]);
    $context=['phone'=>'9000000001','requested_name'=>$name];
    $selection=clientHistorySelection($context);
    identityCheck(count($selection['candidates']),2,'duplicate ID collapsed');
    identityCheck($selection['selected']['key'],'client:a','opened name selects correct shared-phone client');
    identityCheck($selection['selection_required'],false,'no extra selection for opened name');
    identityCheck(clientHistorySelection(['phone'=>'9000000001'])['selection_required'],true,'unresolved name requires choice');
    try{clientHistorySelection($context,'client:unrelated');throw new RuntimeException('forged key accepted');}catch(InvalidArgumentException $e){$checks++;}
    identityCheck(clientHistorySelection(['phone'=>'9000000001','requested_name'=>'Unrelated'])['selected'],null,'untrusted name cannot select unrelated client');
    $sales=clientHistorySales($context,$transport);
    identityCheck(array_column($sales['items'],'sale_id'),[101,102,103],'all exact-name sales across phones and missing phone; other name excluded');
    identityCheck(purchaseAnalytics(purchaseDataset($sales['items']),'2026-10','2026-10','month')['current_total'],'31.31','chart totals by name');
    identityCheck(purchaseTree(purchaseDataset($sales['items']))[0]['total_amount'],'31.31','tree totals by name');
    $before=$requests;clientHistorySales($context,$transport);
    identityCheck($requests,$before,'name query cached');
    $email=['phone'=>'','matches'=>[['name'=>$name,'client_id'=>'a']],'requested_name'=>$name];
    identityCheck(array_column(clientHistorySales($email,$transport)['items'],'sale_id'),[101,102,103],'email history needs no phone');
    $fallback=['phone'=>'','client_name'=>$name];
    identityCheck(array_column(clientHistorySales($fallback,$transport)['items'],'sale_id'),[101,102,103],'persisted interaction name supports missing directory entry');
    scenario('namesakes',[directoryRow('a',$name,'+79000000001'),directoryRow('b',$name,'+79000000001')]);
    identityCheck(array_column(clientHistorySales($context,$transport)['items'],'sale_id'),[101,102,103],'same name across directory IDs shares sales history');
    identityCheck(clientHistorySelection(['phone'=>'9000000001'])['selection_required'],false,'same name with different IDs auto-resolves without chooser');
    identityCheck(clientHistorySelection(['phone'=>'9000000001','requested_name'=>'','client_name'=>$name])['selected']['name'],$name,'empty browser hint falls back to saved name');
    echo "client_history_identity_test: $checks checks OK\n";
} finally {
    putenv($oldStorage===false?'CALLTRACK_STORAGE_DIR':'CALLTRACK_STORAGE_DIR='.$oldStorage);
    putenv($oldSqlite===false?'CALLTRACK_CLIENTS_DISABLE_SQLITE':'CALLTRACK_CLIENTS_DISABLE_SQLITE='.$oldSqlite);
    if(is_dir($temp)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($temp);
    }
}
