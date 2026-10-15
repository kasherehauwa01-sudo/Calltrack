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
        $sql = 'SELECT id_db,phone,user_phone,manager,client FROM calls WHERE id_db=:id';
        if ($scope) { $sql .= ' AND user_phone=:owner'; $params[':owner']=$scope['user_phone']; }
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $row=$stmt->fetch();
        if (!$row) sendJson(['status'=>'error','message'=>'Клиент недоступен'],403);
        $phone=normalizeClientPhone((string)$row['phone']);
        $matches=$phone!=='' ? lookupClientDetailsByPhones([$phone])[$phone] ?? [] : [];
        return ['client_name'=>(string)$row['client'],'requested_name'=>(string)($input['client_name']??''),'phone'=>$phone,'matches'=>$matches,'found_in_1c'=>(bool)$matches,
            'user_phone'=>(string)$row['user_phone'],'manager'=>(string)$row['manager'],'call_id'=>(int)$callId];
    }
    $stmt=$pdo->prepare('SELECT manager_name,client_email,to_emails,client_name FROM email_messages WHERE id=:id');
    $stmt->execute([':id'=>$emailId]); $row=$stmt->fetch();
    if (!$row || ($scope && canonicalManagerName((string)$row['manager_name'],canonicalManagerNames($pdo))!==$scope['manager'])) {
        sendJson(['status'=>'error','message'=>'Клиент недоступен'],403);
    }
    $email=normalizeClientEmail((string)($row['client_email'] ?: $row['to_emails']));
    $matches=$email!=='' ? lookupClientDetailsByEmails([$email])[$email] ?? [] : [];
    $phones=array_values(array_unique(array_filter(array_map(static fn($m)=>normalizeClientPhone((string)$m['phone']),$matches))));
    return ['client_name'=>(string)$row['client_name'],'requested_name'=>(string)($input['client_name']??''),'phone'=>count($phones)===1?$phones[0]:'','matches'=>$matches,'found_in_1c'=>(bool)$matches,
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
    $matches=$context['phone']!==''?readClientMatchesCache($context['phone']):($context['matches']??[]);
    if($matches===null)throw new RuntimeException('Справочник клиентов недоступен');
    $candidates=clientHistoryCandidates($matches);
    if(!$candidates && trim((string)($context['client_name']??''))!==''){
        $candidates=clientHistoryCandidates([['name'=>$context['client_name']]]);
    }
    $selected=null;
    foreach($candidates as $candidate)if($candidate['key']===$requestedKey)$selected=$candidate;
    if($requestedKey!=='' && $selected===null)throw new InvalidArgumentException('Выберите клиента из списка для этого взаимодействия');
    if($requestedKey===''){
        $name=normalizeSalesJournalClientName(trim((string)($context['requested_name']??''))?:((string)($context['client_name']??'')));
        foreach($candidates as $candidate)if($name!=='' && normalizeSalesJournalClientName($candidate['name'])===$name){$selected=$candidate;break;}
        if($selected===null && count(array_unique(array_map(static fn($c)=>normalizeSalesJournalClientName($c['name']),$candidates)))===1)$selected=$candidates[0];
    }
    return ['candidates'=>$candidates,'selected'=>$selected,'selection_required'=>count($candidates)>1 && $selected===null];
}

/** Search every sale by the selected exact name, irrespective of its phone. */
function clientHistorySales(array $context, ?callable $transport=null): array
{
    $selection=clientHistorySelection($context,(string)($context['client_key']??''));
    if($selection['selection_required'])throw new InvalidArgumentException('Выберите клиента для истории покупок');
    $name=trim((string)($selection['selected']['name']??''));
    if($name==='')throw new InvalidArgumentException('Для истории покупок нужно наименование клиента');
    $normalized=normalizeSalesJournalClientName($name);
    $clients=[['key'=>'name:'.hash('sha256',$normalized),'id'=>'','phone'=>'','name'=>$name]];
    $result=loadSalesJournalBatchCached($clients,'0001-01-01','9999-12-31',$transport);
    // Recheck cached and upstream data before aggregation and detail authorization.
    $result['items']=array_values(array_filter($result['items'],static fn($item)=>normalizeSalesJournalClientName((string)($item['client']??''))===$normalized));
    $result['excluded_count']=0;
    return $result;
}
