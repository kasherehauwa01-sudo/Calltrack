<?php
declare(strict_types=1);
require_once __DIR__.'/client_catalog.php';
require_once __DIR__.'/sales_journal.php';

class SalesBuyerUpgradeRequired extends RuntimeException {}

/** Only a bounded Sales Journal page is loaded. This path never reads the Clients catalogue. */
function salesBuyerPage(array $managers,array $user,array $input,?callable $transport=null): array
{
    clientCatalogPage([],$managers,[],$user,$input); // shared input and role validation, no directory loading
    $scope=webManagerScope($user);$filter=(string)($input['manager']??'');$manager=null;
    if($scope)$manager=(string)$scope['manager'];
    elseif($filter!=='')foreach($managers as $m)if((string)$m['id']===$filter)$manager=$m['display_name'];
    $request=['page'=>(int)($input['page']??1),'page_size'=>(int)($input['page_size']??50),'search'=>trim((string)($input['search']??'')),
        'activity'=>(string)($input['activity']??'all'),'manager'=>$manager];
    if(isset($input['sales_client_key'])){
        if(!is_string($input['sales_client_key'])||trim($input['sales_client_key'])===''||mb_strlen($input['sales_client_key'])>512)throw new InvalidArgumentException('Передайте ключ покупателя');
        $request['client_key']=$input['sales_client_key'];$request['page_size']=1;
    }
    try{$data=salesJournalRequest('POST','/api/integrations/calltrack/buyers',$request,$transport);}
    catch(RuntimeException $e){if($e->getMessage()==='Sales Journal HTTP 404')throw new SalesBuyerUpgradeRequired('Обновите Sales Journal: требуется API списка покупателей');throw $e;}
    if(!is_array($data['items']??null)||count($data['items'])>$request['page_size']||!is_int($data['total']??null)||$data['total']<0||!is_int($data['page']??null)||$data['page']<1||($data['page_size']??null)!==$request['page_size'])throw new RuntimeException('Некорректная страница покупателей');
    $items=[];
    foreach($data['items'] as $row){
        if(!is_array($row)||!is_string($row['client_key']??null)||!is_string($row['name']??null)||!is_array($row['phones']??null)||count($row['phones'])>100||!is_array($row['unique_phones']??null)||(int)($row['purchase_count']??0)<1)throw new RuntimeException('Некорректные данные покупателя');
        if(trim($row['client_key'])===''||mb_strlen($row['client_key'])>512||$row['client_key']!==mb_strtolower(trim($row['name']))||isset($request['client_key'])&&$row['client_key']!==$request['client_key'])throw new RuntimeException('Несогласованный ключ покупателя');
        $owner=clientCatalogOwner(['manager'=>(string)($row['manager']??'')],$managers);
        if(($scope&&(!$owner||(int)$owner['id']!==(int)$user['id']))||($manager!==null&&(!$owner||managerNameKey($owner['display_name'])!==managerNameKey($manager))))throw new RuntimeException('Несогласованный ответственный менеджер');
        $phones=array_values(array_unique(array_filter(array_map('normalizeClientPhone',$row['phones']))));
        $unique=array_values(array_intersect($phones,array_map('normalizeClientPhone',$row['unique_phones'])));
        $items[]=['id'=>'sales:'.hash('sha256',$row['client_key']),'sales_client_key'=>$row['client_key'],'name'=>$row['name'],'phones'=>$phones,
            'manager'=>$row['manager']??'','last_activity_at'=>null,'last_purchase_at'=>salesJournalDate((string)($row['last_purchase_at']??'')),
            'unique_phones'=>$unique,'owner_id'=>$owner?(int)$owner['id']:null];
    }
    return ['items'=>$items,'total'=>$data['total'],'page'=>$data['page'],'page_size'=>$data['page_size'],
        'managers'=>$scope?[]:array_map(static fn($m)=>['id'=>(string)$m['id'],'name'=>$m['display_name']],$managers)];
}

/** Contact metadata is resolved lazily, in bounded memory, only after buyer selection. */
function salesBuyerContact(array $buyer,array $managers): array
{
    require_once __DIR__.'/client_catalog_json_stream.php';
    $phones=array_fill_keys($buyer['unique_phones'],[]);$candidate=null;$names=0;
    foreach(salesBuyerDirectoryRows() as $row){
        $records=clientCatalogRecords([$row]);if(!$records)continue;$record=reset($records);
        foreach($record['phones'] as $phone)if(isset($phones[$phone])&&count($phones[$phone])<2)$phones[$phone][$record['id']]=true;
        if(mb_strtolower(trim($record['name']))===$buyer['sales_client_key']){$names++;if($names===1)$candidate=$record;else $candidate=null;}
    }
    if($candidate){$owner=clientCatalogOwner($candidate,$managers);if(!$owner||(int)$owner['id']!==$buyer['owner_id'])$candidate=null;}
    $safePhones=[];foreach($phones as $phone=>$ids)if($candidate&&count($ids)===1&&isset($ids[$candidate['id']]))$safePhones[]=(string)$phone;
    $emails=[];
    if($candidate&&$candidate['emails']){
        $owners=array_fill_keys($candidate['emails'],[]);
        foreach(salesBuyerDirectoryRows() as $row){$records=clientCatalogRecords([$row]);if(!$records)continue;$r=reset($records);foreach($r['emails'] as $email)if(isset($owners[$email])&&count($owners[$email])<2)$owners[$email][$r['id']]=true;}
        foreach($owners as $email=>$ids)if(count($ids)===1&&isset($ids[$candidate['id']]))$emails[]=$email;
    }
    return ['client_id'=>$candidate['id']??'','phones'=>$safePhones,'emails'=>$emails];
}
function salesBuyerResolve(PDO $pdo,array $user,array $input): array
{
    $managers=clientCatalogManagers($pdo);
    // Every selected key is re-authorized upstream using the authenticated user's manager scope.
    $result=salesBuyerPage($managers,$user,['sales_client_key'=>$input['sales_client_key']??null]);$buyer=$result['items'][0]??null;
    if(!$buyer)throw new ClientCatalogAccessDenied('Покупатель недоступен');
    $key='buyer-contact-v1|'.clientCatalogRevision().'|'.hash('sha256',json_encode([$buyer,$managers],JSON_THROW_ON_ERROR));
    $contact=clientCatalogCachedArray($key,static fn()=>salesBuyerContact($buyer,$managers),60);
    return ['buyer'=>$buyer,'contact'=>$contact];
}
function salesBuyerHistoryContext(PDO $pdo,array $user,array $input): array
{
    $resolved=salesBuyerResolve($pdo,$user,$input);$buyer=$resolved['buyer'];$contact=$resolved['contact'];
    return ['client_name'=>$buyer['name'],'requested_name'=>$buyer['name'],'phone'=>$contact['phones'][0]??'',
        'matches'=>[['client_id'=>$contact['client_id'],'name'=>$buyer['name'],'fields'=>['Телефоны'=>$contact['phones'],'email'=>$contact['emails']]]],
        'found_in_1c'=>true,'user_phone'=>$buyer['owner_id']?webUserPhone($buyer['owner_id']):'','manager'=>$buyer['manager'],
        'call_id'=>null,'sales_client_key'=>$buyer['sales_client_key'],'directory_direct'=>true,'directory_name_unique'=>true];
}
