<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/client_directory.php';
require_once __DIR__.'/email_sync.php';
require_once __DIR__.'/web_auth.php';

function salesJournalDate(string $value): string
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));
    if(!$date||$date->format('Y-m-d')!==trim($value))throw new InvalidArgumentException('Дата должна иметь формат YYYY-MM-DD');
    return $date->format('Y-m-d');
}

function salesJournalPeriod(array $data): array
{
    $from=salesJournalDate((string)($data['date_from']??''));$to=salesJournalDate((string)($data['date_to']??''));
    if($from>$to)throw new InvalidArgumentException('Начало периода должно быть не позже окончания');
    return [$from,$to];
}

function salesJournalClientKey(array $client): string
{
    return clientIntegrationKey((string)($client['id']??''),(string)($client['phone']??''),(string)($client['name']??''));
}

function normalizeSalesJournalClientName(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)??''));
}

function canonicalizeSalesJournalClients(array $clients): array
{
    $rows=[];
    foreach($clients as $client){if(!is_array($client))continue;$name=trim(preg_replace('/\s+/u',' ',(string)($client['name']??''))??'');$phone=normalizeClientPhone((string)($client['phone']??''));$phones=[];if($phone!=='')$phones[$phone]=$phone;foreach((array)($client['source_keys']??[]) as $source)if(preg_match('/^phone:\+7(\d{10})$/',(string)$source,$match))$phones[$match[1]]=$match[1];if(!$phones&&$name==='')continue;$rows[]=['client'=>$client,'name'=>$name,'normalized_name'=>normalizeSalesJournalClientName($name),'phones'=>array_values($phones)];}
    if(!$rows)return [];
    $parent=range(0,count($rows)-1);
    $find=static function(int $index)use(&$parent,&$find):int{return $parent[$index]===$index?$index:($parent[$index]=$find($parent[$index]));};
    $union=static function(int $left,int $right)use(&$parent,&$find):void{$left=$find($left);$right=$find($right);if($left!==$right)$parent[$right]=$left;};
    $phoneOwner=[];$nameOwner=[];
    foreach($rows as $index=>$row){foreach($row['phones'] as $phone){if(isset($phoneOwner[$phone]))$union($index,$phoneOwner[$phone]);else $phoneOwner[$phone]=$index;}if($row['normalized_name']!==''){if(isset($nameOwner[$row['normalized_name']]))$union($index,$nameOwner[$row['normalized_name']]);else $nameOwner[$row['normalized_name']]=$index;}}
    $groups=[];foreach(array_keys($rows) as $index)$groups[$find($index)][]=$rows[$index];$canonical=[];
    foreach($groups as $group){$phones=[];$names=[];$sourceKeys=[];$id='';foreach($group as $row){$client=$row['client'];if($id===''&&trim((string)($client['id']??''))!=='')$id=trim((string)$client['id']);foreach($row['phones'] as $phone)$phones[$phone]=$phone;if($row['normalized_name']!=='')$names[$row['normalized_name']]=$row['name'];$source=trim((string)($client['key']??''));if($source!=='')$sourceKeys[$source]=$source;foreach((array)($client['source_keys']??[]) as $source)if(trim((string)$source)!=='')$sourceKeys[(string)$source]=(string)$source;}
        foreach($phones as $phone)$sourceKeys['phone:+7'.$phone]='phone:+7'.$phone;$phoneValues=array_values($phones);$normalizedNames=array_keys($names);sort($normalizedNames,SORT_STRING);
        if(count($phoneValues)===1)$key='phone:+7'.$phoneValues[0];elseif(count($phoneValues)>1&&count($normalizedNames)===1)$key='name:'.hash('sha256',$normalizedNames[0]);elseif($phoneValues){$sortedPhones=$phoneValues;sort($sortedPhones,SORT_STRING);$key='entity:'.hash('sha256',implode('|',$sortedPhones)."\0".implode('|',$normalizedNames));}elseif(count($group)===1&&$id!=='')$key='client:'.$id;elseif($normalizedNames)$key='name:'.hash('sha256',$normalizedNames[0]);else $key=$id!==''?'client:'.$id:array_key_first($sourceKeys);
        $sourceKeys[$key]=$key;$canonical[]=['key'=>$key,'id'=>$id,'phone'=>$phoneValues?'+7'.$phoneValues[0]:'','name'=>$names?reset($names):'','source_keys'=>array_values($sourceKeys)];
    }
    return $canonical;
}

function salesJournalCallDurationSeconds(string $value): int
{
    $raw=trim($value);if($raw==='')return 0;
    if(is_numeric(str_replace(',','.',$raw)))return (int)round((float)str_replace(',','.',$raw));
    $parts=array_map('intval',explode(':',$raw));
    return count($parts)===3?$parts[0]*3600+$parts[1]*60+$parts[2]:(count($parts)===2?$parts[0]*60+$parts[1]:0);
}

function salesJournalCommunicationClients(PDO $pdo,array $user,string $manager,string $from,string $to): array
{
    $scope=webManagerScope($user);if($scope)$manager=(string)$scope['manager'];
    if(trim($manager)==='')throw new InvalidArgumentException('Выберите менеджера');
    $params=[':manager'=>$manager,':from'=>$from,':to'=>$to];
    $callWhere='manager=:manager AND call_date>=:from AND call_date<=:to';
    if($scope){$callWhere.=' AND user_phone=:user_phone';$params[':user_phone']=$scope['user_phone'];}
    $stmt=$pdo->prepare("SELECT phone,client,call_type,duration FROM calls WHERE $callWhere");$stmt->execute($params);$calls=$stmt->fetchAll();
    $phones=[];$fallbacks=[];
    foreach($calls as $call){$type=mb_strtolower((string)$call['call_type']);if(!str_contains($type,'вход')&&!str_contains($type,'исход'))continue;if(salesJournalCallDurationSeconds((string)$call['duration'])<10||mb_strtolower(trim((string)$call['client']))==='личный звонок')continue;$phone=normalizeClientPhone((string)$call['phone']);if($phone!=='')$phones[$phone]=$phone;$fallbacks[]=['phone'=>(string)$call['phone'],'name'=>(string)$call['client']];}
    $resolvedByPhone=lookupClientDetailsByPhones(array_values($phones));$clients=[];
    foreach($resolvedByPhone as $matches)foreach($matches as $client){$id=(string)($client['id']??'');$clients[]=['key'=>$id!==''?'client:'.$id:salesJournalClientKey($client),'id'=>$id,'phone'=>(string)($client['phone']??''),'name'=>(string)$client['name']];}
    foreach($fallbacks as $client){$normalized=normalizeClientPhone((string)$client['phone']);if($normalized!==''&&!empty($resolvedByPhone[$normalized]))continue;if(trim($client['phone'])===''&&trim($client['name'])==='')continue;$clients[]=['key'=>salesJournalClientKey($client),'id'=>'','phone'=>(string)$client['phone'],'name'=>(string)$client['name']];}

    ensureEmailTables($pdo);$emailParams=[':manager'=>$manager,':from'=>$from.' 00:00:00',':to'=>$to.' 23:59:59'];
    $outgoing="(email_messages.direction='outgoing' OR (email_messages.from_email<>'' AND LOWER(email_messages.from_email)=LOWER(email_mailboxes.email)))";
    $emailStmt=$pdo->prepare("SELECT email_messages.client_email,email_messages.to_emails,email_messages.client_name FROM email_messages LEFT JOIN email_mailboxes ON email_mailboxes.id=email_messages.mailbox_id WHERE email_messages.manager_name=:manager AND email_messages.sent_at>=:from AND email_messages.sent_at<=:to AND $outgoing");$emailStmt->execute($emailParams);$emails=[];$emailFallbacks=[];
    foreach($emailStmt->fetchAll() as $email){$raw=(string)($email['client_email']?:$email['to_emails']);preg_match_all('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}/i',$raw,$matches);foreach($matches[0]??[] as $address)$emails[strtolower($address)]=strtolower($address);$emailFallbacks[]=['phone'=>'','name'=>(string)$email['client_name']];}
    $resolvedEmailClients=lookupClientDetailsByEmails(array_values($emails));$resolvedNames=[];foreach($resolvedEmailClients as $matches)foreach($matches as $client){$id=(string)($client['id']??'');$clients[]=['key'=>$id!==''?'client:'.$id:salesJournalClientKey($client),'id'=>$id,'phone'=>(string)($client['phone']??''),'name'=>(string)$client['name']];$resolvedNames[mb_strtolower(trim((string)$client['name']))]=true;}
    foreach($emailFallbacks as $client){$name=trim((string)$client['name']);if($name===''||isset($resolvedNames[mb_strtolower($name)]))continue;$clients[]=['key'=>salesJournalClientKey($client),'id'=>'','phone'=>'','name'=>$name];}
    return canonicalizeSalesJournalClients($clients);
}

function salesJournalRequestUrl(string $base,string $path): string
{
    $base=rtrim(trim($base),'/');$path='/'.ltrim($path,'/');
    $integrationRoot='/api/integrations/calltrack';
    // В production встречаются обе безопасные формы настройки: корень Sales Journal
    // и уже полный корень Integration API. Не добавляем integration path второй раз.
    if(str_ends_with($base,$integrationRoot)&&str_starts_with($path,$integrationRoot.'/'))$path=substr($path,strlen($integrationRoot));
    return $base.$path;
}

function salesJournalRequest(string $method,string $path,?array $body=null,?callable $transport=null): array
{
    if($transport)return $transport($method,$path,$body);
    $base=rtrim((string)SALES_JOURNAL_BASE_URL,'/');$token=(string)CALLTRACK_INTEGRATION_TOKEN;
    if($base===''||$token==='')throw new RuntimeException('Интеграция Sales Journal не настроена');
    if(!function_exists('curl_init'))throw new RuntimeException('Расширение cURL недоступно');
    $started=microtime(true);$curl=curl_init(salesJournalRequestUrl($base,$path));$headers=['Accept: application/json','Authorization: Bearer '.$token];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>(int)SALES_JOURNAL_CONNECT_TIMEOUT,CURLOPT_TIMEOUT=>(int)SALES_JOURNAL_TIMEOUT,CURLOPT_HTTPHEADER=>$headers];
    if($body!==null){$encoded=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$options[CURLOPT_POSTFIELDS]=$encoded;$headers[]='Content-Type: application/json';$options[CURLOPT_HTTPHEADER]=$headers;}
    curl_setopt_array($curl,$options);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    error_log('Sales Journal request: path='.$path.' status='.$status.' duration_ms='.(int)round((microtime(true)-$started)*1000));
    return salesJournalDecodeResponse($raw===false?'':(string)$raw,$status,$error);
}

function salesJournalDecodeResponse(string $raw,int $status,string $networkError=''): array
{
    if($networkError!=='')throw new RuntimeException('Sales Journal network error');
    if($status<200||$status>=300)throw new RuntimeException('Sales Journal HTTP '.$status);
    $decoded=json_decode($raw,true);if(!is_array($decoded))throw new RuntimeException('Sales Journal вернул некорректный JSON');
    return $decoded;
}

function salesJournalSummary(array $item): ?array
{
    $id=(int)($item['sale_id']??0);$key=trim((string)($item['client_key']??''));
    if($id<=0||$key==='')return null;
    return ['sale_id'=>$id,'client_key'=>$key,'matched_by'=>in_array(($item['matched_by']??''),['phone','name'],true)?$item['matched_by']:'','sale_date'=>(string)($item['sale_date']??''),'document_number'=>(string)($item['document_number']??''),'client'=>(string)($item['client']??''),'phone'=>(string)($item['phone']??''),'manager'=>$item['manager']??null,'department'=>$item['department']??null,'total_amount'=>(string)($item['total_amount']??'0')];
}

function loadSalesJournalBatch(array $clients,string $from,string $to,?callable $transport=null): array
{
    $clients=canonicalizeSalesJournalClients($clients);$items=[];$requests=0;$aliases=salesJournalClientAliases($clients);
    foreach(array_chunk($clients,500) as $chunk){$requests++;$allowedKeys=array_fill_keys(array_column($chunk,'key'),true);$payload=['clients'=>array_map(static fn($c)=>['key'=>$c['key'],'phone'=>$c['phone'],'name'=>$c['name']],$chunk),'date_from'=>$from,'date_to'=>$to];$response=salesJournalRequest('POST','/api/integrations/calltrack/client-sales',$payload,$transport);foreach(($response['items']??[]) as $item){if(!is_array($item))continue;$summary=salesJournalSummary($item);if($summary&&isset($allowedKeys[$summary['client_key']])&&!isset($items[$summary['sale_id']]))$items[$summary['sale_id']]=$summary;}}
    return ['items'=>array_values($items),'requests'=>$requests,'client_aliases'=>$aliases];
}

function salesJournalClientAliases(array $clients): array
{
    $aliases=[];
    foreach(canonicalizeSalesJournalClients($clients) as $client)foreach($client['source_keys'] as $source)$aliases[$source]=$client['key'];
    return $aliases;
}

function salesJournalCacheDirectory(): string
{
    $storage=trim((string)(getenv('CALLTRACK_STORAGE_DIR')?:''));
    $directory=($storage!==''?rtrim($storage,'/'):dirname(__DIR__).'/storage').'/cache/sales-journal';
    if(!is_dir($directory)&&!@mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('Не удалось создать кэш Sales Journal');
    return $directory;
}

function salesJournalCacheKey(array $clients,string $from,string $to): string
{
    $identity=[];
    foreach(canonicalizeSalesJournalClients($clients) as $client)$identity[]=['key'=>$client['key'],'phone'=>$client['phone'],'name'=>$client['name']];
    usort($identity,static fn(array $left,array $right):int=>strcmp($left['key'],$right['key']));
    return hash('sha256',json_encode(['version'=>2,'from'=>$from,'to'=>$to,'clients'=>$identity],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function readSalesJournalCache(string $key,int $maxAge,array $clients): ?array
{
    $file=salesJournalCacheDirectory().'/'.$key.'.json';$modified=@filemtime($file);
    if($modified===false||$modified<time()-$maxAge)return null;
    $cached=json_decode((string)@file_get_contents($file),true);
    if(!is_array($cached)||($cached['version']??null)!==2||empty($cached['items'])||!is_array($cached['items']))return null;
    return ['items'=>$cached['items'],'client_aliases'=>salesJournalClientAliases($clients),'requests'=>0,'cache_hit'=>true,'cache_stale'=>$modified<time()-SALES_JOURNAL_CACHE_TTL,'cache_age_seconds'=>max(0,time()-$modified)];
}

function writeSalesJournalCache(string $key,array $result): void
{
    $directory=salesJournalCacheDirectory();$file=$directory.'/'.$key.'.json';$temporary=$file.'.'.getmypid().'.tmp';
    if(empty($result['items'])){@unlink($file);return;}
    $json=json_encode(['version'=>2,'items'=>$result['items']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(@file_put_contents($temporary,$json,LOCK_EX)===false||!@rename($temporary,$file)){@unlink($temporary);throw new RuntimeException('Не удалось сохранить кэш Sales Journal');}
    foreach(glob($directory.'/*.json')?:[] as $cachedFile){$modified=@filemtime($cachedFile);if($modified!==false&&$modified<time()-SALES_JOURNAL_STALE_CACHE_TTL)@unlink($cachedFile);}
}

function loadSalesJournalBatchCached(array $clients,string $from,string $to,?callable $transport=null): array
{
    $clients=canonicalizeSalesJournalClients($clients);$key=salesJournalCacheKey($clients,$from,$to);
    try{$cached=readSalesJournalCache($key,SALES_JOURNAL_CACHE_TTL,$clients);if($cached!==null)return $cached;}catch(Throwable $error){error_log('Sales Journal cache read failed: '.$error->getMessage());}
    try{$result=loadSalesJournalBatch($clients,$from,$to,$transport);}
    catch(Throwable $error){try{$stale=readSalesJournalCache($key,SALES_JOURNAL_STALE_CACHE_TTL,$clients);if($stale!==null){error_log('Sales Journal unavailable, stale cache used: '.$error->getMessage());return $stale;}}catch(Throwable $cacheError){error_log('Sales Journal stale cache read failed: '.$cacheError->getMessage());}throw $error;}
    try{writeSalesJournalCache($key,$result);}catch(Throwable $error){error_log('Sales Journal cache write failed: '.$error->getMessage());}
    return $result+['cache_hit'=>false,'cache_stale'=>false,'cache_age_seconds'=>0];
}

function allowSalesJournalDetails(array $items): void
{
    startWebSession();$now=time();$allowed=[];foreach(($_SESSION['sales_journal_allowed']??[]) as $id=>$expires)if((int)$expires>$now)$allowed[(string)$id]=(int)$expires;foreach($items as $item)$allowed[(string)$item['sale_id']]=$now+1800;$_SESSION['sales_journal_allowed']=$allowed;
}

function isSalesJournalDetailAllowed(int $saleId): bool
{
    startWebSession();return (int)($_SESSION['sales_journal_allowed'][(string)$saleId]??0)>time();
}
