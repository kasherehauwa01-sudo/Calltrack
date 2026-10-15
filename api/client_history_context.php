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

function clientHistorySales(array $context, ?callable $transport=null): array
{
    if ($context['phone']==='') throw new InvalidArgumentException('Для истории покупок нужен однозначно определённый телефон клиента');
    $matches=readClientMatchesCache($context['phone']);
    if ($matches===null) throw new RuntimeException('Справочник клиентов недоступен');
    if (count($matches)>1) throw new InvalidArgumentException('Телефон связан с несколькими клиентами 1С. Историю покупок нельзя однозначно определить');
    $phones=[$context['phone']];
    if (count($matches)===1) {
        $match=$matches[0];$id=(string)($match['client_id']??$match['fields']['id']??$match['fields']['client_id']??'');
        if ($id!=='' && clientsSqliteAvailable() && is_file(clientsSqliteFile())) {
            $stmt=clientsOpenSqlite()->prepare('SELECT normalized_phone FROM client_phones WHERE client_id=?');
            $stmt->execute([$id]);$phones=array_merge($phones,$stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $raw=clientRawValue($match['fields']??[],['Телефоны','телефоны','phones','phone_numbers','phone']);
            $phones=array_merge($phones,splitClientPhones($raw));
        }
    }
    $phones=array_values(array_unique(array_filter(array_map('normalizeClientPhone',$phones))));
    if(count($phones)>500)throw new InvalidArgumentException('Слишком много телефонов клиента для одного запроса');
    foreach($phones as $phone){
        $aliases=readClientMatchesCache($phone);
        if($aliases===null || count($aliases)>1)throw new InvalidArgumentException('Один из телефонов клиента не определён однозначно в 1С');
    }
    // Sales Journal has no external client ID. Suppress its name fallback to
    // prevent mixing namesakes; the key remains the existing integration key.
    $clients=array_map(static fn($phone)=>['key'=>'phone:+7'.$phone,'id'=>'','phone'=>'+7'.$phone,'name'=>''],$phones);
    return loadSalesJournalBatchCached($clients,'0001-01-01','9999-12-31',$transport);
}
