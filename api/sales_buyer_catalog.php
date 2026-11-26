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
    return clientIdentitySnapshot(static function($meta)use($buyer,$managers){
        $key='buyer_name:'.$buyer['sales_client_key'];$bucket=clientIdentityBucket($meta,'keys',$key);
        $id=$bucket[$key]??null;
        if(!is_string($id))return ['client_id'=>'','phones'=>[],'emails'=>[]];
        try{$lookup=clientIdentityLookupInSnapshot($meta,$id);}catch(ClientCatalogAccessDenied $e){return ['client_id'=>'','phones'=>[],'emails'=>[]];}
        $candidate=$lookup['record'];$owner=clientCatalogOwner($candidate,$managers);
        if(!$lookup['name_unique']||($candidate['manager_conflict']??false)||!$owner||(int)$owner['id']!==$buyer['owner_id'])return ['client_id'=>'','phones'=>[],'emails'=>[]];
        return ['client_id'=>$id,'phones'=>array_values(array_intersect($buyer['unique_phones'],array_map('strval',array_keys($lookup['phone_index'])))),
            'emails'=>array_keys($lookup['email_index'])];
    });
}
function salesBuyerResolve(PDO $pdo,array $user,array $input): array
{
    $managers=clientCatalogManagers($pdo);
    // Every selected key is re-authorized upstream using the authenticated user's manager scope.
    $result=salesBuyerPage($managers,$user,['sales_client_key'=>$input['sales_client_key']??null]);$buyer=$result['items'][0]??null;
    if(!$buyer)throw new ClientCatalogAccessDenied('Покупатель недоступен');
    $contact=salesBuyerContact($buyer,$managers);
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
