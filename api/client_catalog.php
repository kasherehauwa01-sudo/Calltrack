<?php
declare(strict_types=1);
require_once __DIR__.'/client_directory.php';
require_once __DIR__.'/web_auth.php';
require_once __DIR__.'/client_catalog_cache.php';
require_once __DIR__.'/client_manager.php';

class ClientCatalogAccessDenied extends RuntimeException {}

/** The existing directory remains the only source of clients and assignment. */
function clientCatalogRecords(?iterable $source=null): array
{
    if($source===null)return clientCatalogCachedArray('records-v3|'.clientCatalogRevision(),static fn()=>clientCatalogRecords(clientsCacheRowsForEmailLookup()),120);
    $records=[];$duplicates=[];
    foreach($source??clientsCacheRowsForEmailLookup() as $row){
        $fields=is_array($row['fields']??null)?$row['fields']:[];
        $id=trim((string)($row['id']??$fields['id']??$fields['client_id']??''));
        if($id==='')continue;
        $assignment=clientManagerField($fields);$manager=$assignment['label'];
        $phones=array_values(array_unique(array_filter(array_map('normalizeClientPhone',array_map('strval',(array)($row['phones']??[]))))));
        if(!$phones)$phones=splitClientPhones(clientRawValue($fields,['Телефоны','phones','phone']));
        $emails=clientEmailsFromFields($fields);
        $record=['id'=>$id,'name'=>trim((string)($row['name']??'')),'phones'=>$phones,'emails'=>$emails,
            'manager_conflict'=>$assignment['conflict'],'manager'=>is_string($manager)?trim($manager):'','manager_key'=>is_string($manager)?managerNameKey($manager):'','fields'=>['id'=>$id,'Телефоны'=>$phones,'email'=>$emails,'Менеджер'=>is_string($manager)?trim($manager):'']];
        if(isset($records[$id]))$duplicates[$id]=true;
        $records[$id]=$record;
    }
    foreach($duplicates as $id=>$_)unset($records[$id]);
    return $records;
}

function clientCatalogManagers(PDO $pdo): array
{
    $managers=$pdo->query("SELECT id,display_name FROM web_users WHERE role='manager' AND is_active=1 ORDER BY display_name,id")->fetchAll();
    if(getenv('CALLTRACK_MANAGER_BINDINGS_ENABLED')==='1'){
        require_once __DIR__.'/manager_bindings_service.php';
        $bindings=managerBindingRows($pdo);
        // The same immutable batch of explicit aliases is attached once, not queried per client.
        if($managers)$managers[0]['clientsvr_bindings']=$bindings;
    }
    return $managers;
}

/** Match the existing full-name convention, never a substring/fuzzy match. */
function clientCatalogOwnerIndex(array $managers): array
{
    $index=[];$duplicates=[];
    foreach($managers as $manager){$key=managerNameKey((string)$manager['display_name']);if(isset($index[$key]))$duplicates[$key]=true;$index[$key]=$manager;}
    foreach($duplicates as $key=>$_)unset($index[$key]);
    $explicit=[];
    foreach($managers[0]['clientsvr_bindings']??[] as $binding){
        $explicit[$binding['designation_key']]=(($binding['role']??'')==='manager'&&(int)($binding['is_active']??0)===1)
            ?['id'=>$binding['web_user_id'],'display_name'=>$binding['display_name']]:null;
    }
    $index['__explicit_bindings']=$explicit;
    return $index;
}

function clientCatalogOwner(array $record,array $managers,?array $index=null): ?array
{
    $key=$record['manager_key']??managerNameKey($record['manager']);if($key==='')return null;
    $index??=clientCatalogOwnerIndex($managers);
    $designation=clientManagerDesignationKey((string)($record['manager']??''));
    if(array_key_exists($designation,$index['__explicit_bindings']??[]))return $index['__explicit_bindings'][$designation];
    return $index[$key]??null;
}

function clientCatalogVisible(array $user,array $record,array $managers): bool
{
    if(webManagerScope($user)===null)return true;
    $owner=clientCatalogOwner($record,$managers);
    return $owner!==null&&(int)$owner['id']===(int)$user['id'];
}

function clientCatalogResolve(PDO $pdo,array $user,string $id): array
{
    $records=clientCatalogRecords();$record=$records[$id]??null;$managers=clientCatalogManagers($pdo);
    if(!$record||!clientCatalogVisible($user,$record,$managers))throw new ClientCatalogAccessDenied('Клиент недоступен');
    $owner=clientCatalogOwner($record,$managers);
    return ['record'=>$record,'owner'=>$owner,'records'=>$records,'managers'=>$managers];
}

/** Full client context also works without a single call/email. Browser owner fields are ignored. */
function clientCatalogHistoryContext(PDO $pdo,array $user,array $input): array
{
    if(!is_string($input['client_id']??null)||trim($input['client_id'])===''||strlen($input['client_id'])>255)throw new InvalidArgumentException('Передайте client_id');
    $resolved=clientCatalogResolve($pdo,$user,trim($input['client_id']));$record=$resolved['record'];$owner=$resolved['owner'];
    $match=['client_id'=>$record['id'],'name'=>$record['name'],'phone'=>$record['phones'][0]??'','fields'=>$record['fields']];
    $nameKey=static fn($value)=>mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)??''));
    $sameNames=array_filter($resolved['records'],static fn($r)=>$nameKey($r['name'])===$nameKey($record['name']));
    return ['client_name'=>$record['name'],'requested_name'=>$record['name'],'phone'=>$record['phones'][0]??'',
        'matches'=>[$match],'found_in_1c'=>true,'user_phone'=>$owner?webUserPhone((int)$owner['id']):'',
        'manager'=>$owner?(string)$owner['display_name']:$record['manager'],'call_id'=>null,'client_id'=>$record['id'],
        'directory_direct'=>true,'directory_name_unique'=>count($sameNames)===1];
}

/** Shared addresses do not assign activity/history to an arbitrary client. */
function clientCatalogAddressIndex(array $records,string $field): array
{
    $index=[];
    foreach($records as $record)foreach($record[$field] as $address)$index[$address][$record['id']]=true;
    $result=[];foreach($index as $address=>$ids)if(count($ids)===1)$result[(string)$address]=(string)array_key_first($ids);
    return $result;
}

/** Two bulk aggregate queries, independent of the number of directory clients. */
function clientCatalogActivity(PDO $pdo,array $records,array $managers,array $user): array
{
    $phones=clientCatalogAddressIndex($records,'phones');$emails=clientCatalogAddressIndex($records,'emails');$result=[];
    $ownerIndex=clientCatalogOwnerIndex($managers);
    $ownerIds=[];foreach($records as $id=>$record){$owner=clientCatalogOwner($record,$managers,$ownerIndex);if($owner)$ownerIds[$id]=(int)$owner['id'];}
    $scope=webManagerScope($user);
    $sql="SELECT user_phone,phone,MAX(CONCAT(call_date,' ',call_time)) AS last_at FROM calls WHERE call_type IN ('Входящий','Исходящий') AND COALESCE(client,'')<>'Личный звонок' AND (CASE WHEN duration LIKE '%:%' THEN TIME_TO_SEC(duration) ELSE CAST(REPLACE(duration,',','.') AS DECIMAL(12,2)) END)>=10";
    $params=[];if($scope){$sql.=' AND user_phone=:owner';$params[':owner']=$scope['user_phone'];}
    $stmt=$pdo->prepare($sql.' GROUP BY user_phone,phone');$stmt->execute($params);
    foreach($stmt as $row){$id=$phones[normalizeClientPhone((string)$row['phone'])]??null;
        if($id!==null&&isset($ownerIds[$id])&&webUserPhone($ownerIds[$id])===(string)$row['user_phone'])$result[$id]=max($result[$id]??'',(string)$row['last_at']);}
    $sql="SELECT e.manager_name,e.client_email,e.to_emails,MAX(e.sent_at) AS last_at FROM email_messages e LEFT JOIN email_mailboxes m ON m.id=e.mailbox_id WHERE (e.direction='outgoing' OR (e.from_email<>'' AND LOWER(e.from_email)=LOWER(m.email))) GROUP BY e.manager_name,e.client_email,e.to_emails";
    foreach($pdo->query($sql) as $row){
        $emailOwner=clientCatalogOwner(['manager'=>(string)$row['manager_name']],$managers,$ownerIndex);
        if(!$emailOwner||($scope&&(int)$emailOwner['id']!==(int)$user['id']))continue;
        foreach(clientEmailsFromFields(['email'=>$row['client_email'].','.$row['to_emails']]) as $address){$id=$emails[$address]??null;
            if($id!==null&&($ownerIds[$id]??null)===(int)$emailOwner['id'])$result[$id]=max($result[$id]??'',(string)$row['last_at']);}
    }
    return $result;
}

function clientCatalogPage(array $records,array $managers,array $activity,array $user,array $input,?DateTimeImmutable $now=null,array $purchases=[]): array
{
    $now??=new DateTimeImmutable('now',new DateTimeZone('Europe/Volgograd'));
    foreach(['manager','activity','sort','search','page','page_size'] as $key)if(isset($input[$key])&&!is_scalar($input[$key]))throw new InvalidArgumentException('Некорректный фильтр');
    $filter=trim((string)($input['manager']??''));$scope=webManagerScope($user);
    if($scope&&$filter!==''&&$filter!==(string)$user['id'])throw new ClientCatalogAccessDenied('Фильтр менеджера недоступен');
    if($filter!==''&&!in_array($filter,array_map(static fn($m)=>(string)$m['id'],$managers),true))throw new InvalidArgumentException('Неизвестный менеджер');
    $mode=(string)($input['activity']??'all');
    if(!in_array($mode,['all','yesterday','7','30','inactive'],true))throw new InvalidArgumentException('Неизвестный фильтр');
    $page=filter_var($input['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$size=filter_var($input['page_size']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
    if($page===false||$size===false)throw new InvalidArgumentException('Некорректная страница');
    $search=mb_strtolower(trim((string)($input['search']??'')));if(mb_strlen($search)>200)throw new InvalidArgumentException('Слишком длинный поиск');
    $digits=preg_match('/^[+\d\s().-]+$/u',$search)?(preg_replace('/\D+/','',$search)??''):'';if(strlen($digits)>=10)$digits=normalizeClientPhone($digits);
    $today=$now->setTime(0,0);$items=[];$ownerIndex=clientCatalogOwnerIndex($managers);
    foreach($records as $record){
        $owner=clientCatalogOwner($record,$managers,$ownerIndex);
        if($scope&&(!$owner||(int)$owner['id']!==(int)$user['id']))continue;
        if($filter!==''&&(!$owner||(string)$owner['id']!==$filter))continue;
        if($search!==''&&!str_contains(mb_strtolower($record['name']),$search)&&($digits===''||!array_filter($record['phones'],static fn($p)=>str_contains($p,$digits))))continue;
        $stamp=$activity[$record['id']]??null;$date=$stamp?new DateTimeImmutable($stamp,$today->getTimezone()):null;
        if($mode==='yesterday'&&(!$date||$date<$today->modify('-1 day')||$date>=$today))continue;
        if(in_array($mode,['7','30'],true)&&(!$date||$date<$today->modify('-'.((int)$mode-1).' days')||$date>$now))continue;
        if($mode==='inactive'&&$date&&$date>=$now->modify('-30 days'))continue;
        $items[]=['id'=>$record['id'],'name'=>$record['name'],'phones'=>$record['phones'],'manager'=>$owner?(string)$owner['display_name']:$record['manager'],'last_activity_at'=>$stamp,'last_purchase_at'=>$purchases[$record['id']]??null];
    }
    usort($items,static function($a,$b){
        $cmp=strcmp($b['last_purchase_at']??'',$a['last_purchase_at']??'');
        return $cmp?:strcmp(mb_strtolower($a['name']),mb_strtolower($b['name']))?:strcmp($a['id'],$b['id']);
    });
    $total=count($items);$page=min($page,max(1,(int)ceil($total/$size)));
    return ['items'=>array_slice($items,($page-1)*$size,$size),'total'=>$total,'page'=>$page,'page_size'=>$size,
        'managers'=>$scope?[]:array_map(static fn($m)=>['id'=>(string)$m['id'],'name'=>(string)$m['display_name']],$managers)];
}
