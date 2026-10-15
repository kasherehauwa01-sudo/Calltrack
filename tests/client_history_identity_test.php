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
    $requests++;$items=[];
    $rows=[
        [101,'+79000000001',' Альфа ','10.10'],[102,'+79000000001','Бета','20.20'],[103,'+79000000001','','90.00'],
        [104,'+79000000002','Старое название Альфы','1.01'],[105,'+79000000003','Бета','2.02'],
    ];
    foreach($body['clients'] as $client){
        identityCheck($client['name'],'','no name-only upstream fallback');
        foreach($rows as [$id,$phone,$name,$amount])if($client['phone']===$phone)$items[]=['sale_id'=>$id,'client_key'=>$client['key'],'matched_by'=>'phone','sale_date'=>'2026-10-01','document_number'=>'РН-'.$id,'phone'=>$phone,'client'=>$name,'total_amount'=>$amount];
    }
    return ['items'=>$items];
};
try {
    scenario('duplicates',[directoryRow('a','Альфа','+79000000001'),directoryRow('a','Альфа','8 (900) 000-00-01')]);
    $selection=clientHistorySelection(['phone'=>'9000000001']);
    identityCheck(count($selection['candidates']),1,'same stable ID collapsed');
    identityCheck($selection['selection_required'],false,'duplicate rows are not ambiguous');
    identityCheck($selection['selected']['key'],'client:a','stable selected key');
    identityCheck(count(clientHistorySales(['phone'=>'9000000001'],$transport)['items']),3,'duplicates no longer block history');
    $noIds=clientHistoryCandidates([
        ['name'=>'  Альфа  ','phone'=>'+79000000001','fields'=>[]],
        ['name'=>'альфа','phone'=>'89000000001','fields'=>[]],
    ]);
    identityCheck(count($noIds),1,'identical legacy records deduplicated');
    identityCheck(clientHistoryCandidates([['client_id'=>'','name'=>'A','phone'=>'+79000000001','fields'=>['ID'=>'legacy']]])[0]['key'],'client:legacy','empty top-level ID falls back to field ID');

    scenario('shared',[directoryRow('a','Альфа','+79000000001, +79000000002'),directoryRow('a','Альфа','+79000000001, +79000000002'),directoryRow('b','Бета','+79000000001, +79000000003')]);
    $context=['phone'=>'9000000001'];$selection=clientHistorySelection($context);
    identityCheck($selection['selection_required'],true,'different clients need selection');
    identityCheck(count($selection['candidates']),2,'duplicate rows do not add choices');
    $before=$requests;
    try{clientHistorySales($context,$transport);throw new RuntimeException('selection bypassed');}catch(InvalidArgumentException $e){$checks++;}
    identityCheck($requests,$before,'no upstream request before selection');
    try{clientHistorySelection($context,'client:unrelated');throw new RuntimeException('forged key accepted');}catch(InvalidArgumentException $e){$checks++;}
    $a=clientHistorySales($context+['client_key'=>'client:a'],$transport);
    identityCheck(array_column($a['items'],'sale_id'),[101,104],'selected A only; includes own alternate phone');
    identityCheck($a['excluded_count'],2,'other-client and nameless shared sales excluded');
    identityCheck(purchaseAnalytics(purchaseDataset($a['items']),'2026-10','2026-10','month')['current_total'],'11.11','chart uses filtered dataset');
    $b=clientHistorySales($context+['client_key'=>'client:b'],$transport);
    identityCheck(array_column($b['items'],'sale_id'),[102,105],'selected B only');
    $before=$requests;$again=clientHistorySales($context+['client_key'=>'client:a'],$transport);
    identityCheck($requests,$before,'repeat selection uses cache');
    identityCheck(array_column($again['items'],'sale_id'),[101,104],'cache filtered per selection');
    $aFromUniquePhone=clientHistorySales(['phone'=>'9000000002'],$transport);
    identityCheck(array_column($aFromUniquePhone['items'],'sale_id'),[101,104],'shared secondary phone does not block or mix');

    scenario('namesakes',[directoryRow('a','Альфа','+79000000001'),directoryRow('b',' АЛЬФА ','+79000000001')]);
    identityCheck(count(clientHistorySelection($context)['candidates']),2,'distinct stable IDs not merged by name');
    $ambiguous=clientHistorySales($context+['client_key'=>'client:a'],$transport);
    identityCheck($ambiguous['items'],[],'same phone AND same name cannot distinguish IDs');
    identityCheck($ambiguous['excluded_count'],3,'indistinguishable sales reported');
    $outside=filterClientHistorySales([['phone'=>'+79000000009','client'=>'Альфа']],['9000000001'=>['альфа']]);
    identityCheck($outside['items'],[],'matching name alone never sufficient');

    // SQLite path carries full phone membership via the stable client ID.
    putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=0');
    if(clientsSqliteAvailable()){
        putenv('CALLTRACK_STORAGE_DIR='.$temp.'/sqlite');
        $db=clientsOpenSqlite();
        foreach(normalizeClientsPayload([directoryRow('a','Альфа','+79000000001, +79000000002'),directoryRow('b','Бета','+79000000001')]) as $client)clientsSqliteUpsert($db,$client,$client['id']);
        identityCheck(clientHistorySelection($context)['selection_required'],true,'SQLite shared phone selection');
        identityCheck(array_column(clientHistorySales($context+['client_key'=>'client:a'],$transport)['items'],'sale_id'),[101,104],'SQLite stable ID resolves alternate phone');
        $db=null;
    }
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
