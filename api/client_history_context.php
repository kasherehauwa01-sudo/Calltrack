<?php
declare(strict_types=1);
require_once __DIR__.'/sales_journal.php';

/** Resolve a persisted interaction, never a browser-supplied client identity. */
function clientHistoryContext(PDO $pdo, array $user, array $input): array
{
    $scope = webManagerScope($user);
    $callId = filter_var($input['call_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $emailId = filter_var($input['email_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$callId && !$emailId) throw new InvalidArgumentException('Выберите взаимодействие с клиентом');
    if ($callId) {
        $params = [':id'=>$callId];
        $sql = 'SELECT id_db,phone,user_phone,manager FROM calls WHERE id_db=:id';
        if ($scope) { $sql .= ' AND user_phone=:owner'; $params[':owner']=$scope['user_phone']; }
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $row=$stmt->fetch();
        if (!$row) sendJson(['status'=>'error','message'=>'Клиент недоступен'],403);
        $phone=normalizeClientPhone((string)$row['phone']);
        $matches=$phone!=='' ? lookupClientDetailsByPhones([$phone])[$phone] ?? [] : [];
        return ['phone'=>$phone,'matches'=>$matches,'found_in_1c'=>(bool)$matches,
            'user_phone'=>(string)$row['user_phone'],'manager'=>(string)$row['manager'],'call_id'=>(int)$callId];
    }
    $stmt=$pdo->prepare('SELECT manager_name,client_email,to_emails FROM email_messages WHERE id=:id');
    $stmt->execute([':id'=>$emailId]); $row=$stmt->fetch();
    if (!$row || ($scope && canonicalManagerName((string)$row['manager_name'],canonicalManagerNames($pdo))!==$scope['manager'])) {
        sendJson(['status'=>'error','message'=>'Клиент недоступен'],403);
    }
    $email=normalizeClientEmail((string)($row['client_email'] ?: $row['to_emails']));
    $matches=$email!=='' ? lookupClientDetailsByEmails([$email])[$email] ?? [] : [];
    $phones=array_values(array_unique(array_filter(array_map(static fn($m)=>normalizeClientPhone((string)$m['phone']),$matches))));
    return ['phone'=>count($phones)===1?$phones[0]:'','matches'=>$matches,'found_in_1c'=>(bool)$matches,
        'user_phone'=>'','manager'=>(string)$row['manager_name'],'call_id'=>null];
}

/** Collapse repeated directory rows by stable identity, not by row count. */
function clientHistoryCandidates(array $matches): array
{
    $candidates=[];
    foreach($matches as $match){
        $fields=is_array($match['fields']??null)?$match['fields']:[];
        $id=trim((string)($match['client_id']??''));
        if($id==='')$id=trim((string)clientRawValue($fields,['id','client_id','ID']));
        $name=trim((string)($match['name']??''));
        $phones=splitClientPhones(clientRawValue($fields,['Телефоны','телефоны','phones','phone_numbers','phone']));
        $phone=normalizeClientPhone((string)($match['phone']??''));
        if($phone!=='')$phones[]=$phone;
        $phones=array_values(array_unique($phones));sort($phones,SORT_STRING);
        $normalizedName=normalizeSalesJournalClientName($name);
        $key=$id!==''?'client:'.$id:'record:'.hash('sha256',$normalizedName."\0".implode('|',$phones));
        if(!isset($candidates[$key]))$candidates[$key]=['key'=>$key,'id'=>$id,'name'=>$name,'names'=>[],'phones'=>[]];
        if($normalizedName!=='')$candidates[$key]['names'][$normalizedName]=true;
        foreach($phones as $p)$candidates[$key]['phones'][$p]=$p;
    }
    foreach($candidates as &$candidate){$candidate['names']=array_keys($candidate['names']);$candidate['phones']=array_values($candidate['phones']);}unset($candidate);
    return array_values($candidates);
}

function clientHistorySelection(array $context, string $requestedKey=''): array
{
    $matches=$context['phone']!==''?readClientMatchesCache($context['phone']):[];
    if($matches===null)throw new RuntimeException('Справочник клиентов недоступен');
    $candidates=clientHistoryCandidates($matches);
    $selected=null;
    foreach($candidates as $candidate)if($candidate['key']===$requestedKey)$selected=$candidate;
    if($requestedKey!=='' && $selected===null)throw new InvalidArgumentException('Выберите клиента из списка для этого взаимодействия');
    if($requestedKey==='' && count($candidates)===1)$selected=$candidates[0];
    return ['candidates'=>$candidates,'selected'=>$selected,'selection_required'=>count($candidates)>1 && $selected===null];
}

/** Shared phones require both phone membership and an unambiguous directory name. */
function filterClientHistorySales(array $items, array $phoneRules): array
{
    $filtered=[];$excluded=0;
    foreach($items as $item){
        $phone=normalizeClientPhone((string)($item['phone']??''));
        if(!array_key_exists($phone,$phoneRules)){$excluded++;continue;}
        $names=$phoneRules[$phone];
        if($names!==null && !in_array(normalizeSalesJournalClientName((string)($item['client']??'')),$names,true)){$excluded++;continue;}
        $filtered[]=$item;
    }
    return ['items'=>$filtered,'excluded_count'=>$excluded];
}

function clientHistorySales(array $context, ?callable $transport=null): array
{
    if ($context['phone']==='') throw new InvalidArgumentException('Для истории покупок нужен однозначно определённый телефон клиента');
    $selection=clientHistorySelection($context,(string)($context['client_key']??''));
    if($selection['selection_required'])throw new InvalidArgumentException('Выберите клиента для истории покупок');
    $selected=$selection['selected'];
    $phones=$selected?$selected['phones']:[$context['phone']];
    if($selected && $selected['id']!=='' && clientsSqliteAvailable() && is_file(clientsSqliteFile())){
        $stmt=clientsOpenSqlite()->prepare('SELECT normalized_phone FROM client_phones WHERE client_id=?');
        $stmt->execute([$selected['id']]);$phones=array_merge($phones,$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $phones=array_values(array_unique(array_filter(array_map('normalizeClientPhone',$phones))));
    if(count($phones)>500)throw new InvalidArgumentException('Слишком много телефонов клиента для одного запроса');
    $rules=[];
    foreach($phones as $phone){
        $matches=readClientMatchesCache($phone);
        if($matches===null)throw new RuntimeException('Справочник клиентов недоступен');
        $owners=clientHistoryCandidates($matches);
        $others=array_values(array_filter($owners,static fn($owner)=>$owner['key']!==($selected['key']??'')));
        // Unknown numbers still use their authorized interaction's phone. Known
        // aliases shared with another identity must not mix that identity's sales.
        if($selected && $others){
            $otherNames=[];foreach($others as $other)$otherNames=array_merge($otherNames,$other['names']);
            $rules[$phone]=array_values(array_diff($selected['names'],$otherNames));
        }elseif(!$selected && count($owners)>1){$rules[$phone]=[];}
        else{$rules[$phone]=null;}
    }
    $clients=array_map(static fn($phone)=>['key'=>'phone:+7'.$phone,'id'=>'','phone'=>'+7'.$phone,'name'=>''],$phones);
    $result=loadSalesJournalBatchCached($clients,'0001-01-01','9999-12-31',$transport);
    $filtered=filterClientHistorySales($result['items'],$rules);
    $result['items']=$filtered['items'];$result['excluded_count']=$filtered['excluded_count'];
    return $result;
}
