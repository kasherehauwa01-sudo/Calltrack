<?php
declare(strict_types=1);
require_once __DIR__.'/client_history_context.php';

// No shared history/business timezone is configured. Refresh-job timezones are unrelated.
const MAX_HISTORY_TIMEZONE = 'Europe/Volgograd';

/** Directory detail responses use id; persisted cache rows use client_id. */
function maxHistoryCandidates(array $matches): array
{
    foreach ($matches as &$match) {
        if (is_array($match) && !isset($match['client_id']) && isset($match['id'])) $match['client_id']=$match['id'];
    }
    unset($match);
    return clientHistoryCandidates($matches);
}

/** Preserve stable IDs for email contexts: the legacy detail lookup deduplicates by display name. */
function maxHistoryEmailMatches(string $email, iterable $records): array
{
    $matches=[];
    if($email==='')return [];
    foreach($records as $record){
        if(in_array($email,clientEmailsFromFields(is_array($record['fields']??null)?$record['fields']:[]),true))$matches[]=$record;
    }
    return $matches;
}

function maxHistoryContext(PDO $pdo, array $user, array $input): array
{
    if(array_key_exists('client_id',$input))return clientHistoryContext($pdo,$user,$input);
    if(filter_var($input['call_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return clientHistoryContext($pdo,$user,$input);
    $id=filter_var($input['email_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)throw new InvalidArgumentException('Interaction required');
    $stmt=$pdo->prepare('SELECT manager_name,client_email,to_emails FROM email_messages WHERE id=:id');
    $stmt->execute([':id'=>$id]);$row=$stmt->fetch();$scope=webManagerScope($user);
    // Same persisted email access rule used by clientHistoryContext.
    if(!$row||($scope&&canonicalManagerName((string)$row['manager_name'],canonicalManagerNames($pdo))!==$scope['manager']))sendJson(['status'=>'error','message'=>'Клиент недоступен'],403);
    $email=normalizeClientEmail((string)($row['client_email']?:$row['to_emails']));
    return ['matches'=>maxHistoryEmailMatches($email,clientsCacheRowsForEmailLookup()),'user_phone'=>'','manager'=>(string)$row['manager_name']];
}

/** No display-name fallback: require one stable directory client and recheck each of its phones. */
function maxHistoryPhones(array $context, ?callable $lookup = null): array
{
    $lookup ??= 'readClientMatchesCache';
    $clients = maxHistoryCandidates($context['matches'] ?? []);
    if (count($clients) !== 1 || $clients[0]['id'] === '') return [];
    $client = $clients[0];$phones = [];$records=[];
    $read = static function (string $phone) use ($lookup,&$records): array {
        if (!array_key_exists($phone,$records)) {
            $matches=$lookup($phone);
            if ($matches === null) throw new RuntimeException('Client directory unavailable');
            $records[$phone]=maxHistoryCandidates($matches);
        }
        return $records[$phone];
    };
    // The detail API returns only the matched phone. Rehydrate the full persisted record
    // through its indexed phone cache before considering the client's other numbers.
    $candidates=$client['phones'];
    foreach ($client['phones'] as $value) {
        $phone=normalizeClientPhone($value);if($phone==='')continue;
        $owners=$read($phone);
        if(count($owners)===1&&$owners[0]['id']===$client['id'])$candidates=array_merge($candidates,$owners[0]['phones']);
    }
    foreach (array_unique($candidates) as $value) {
        $phone = normalizeClientPhone($value);
        if ($phone === '') continue;
        $owners = $read($phone);
        if (count($owners) === 1 && $owners[0]['id'] !== '' && $owners[0]['id'] === $client['id']) $phones[] = $phone;
    }
    return array_values(array_unique($phones));
}

/** Persisted call owner, or authenticated manager; email-only admin contexts require an exact unique owner. */
function maxHistoryOwner(PDO $pdo, array $user, array $context): string
{
    $scope = webManagerScope($user);
    if ($scope) return $scope['user_phone'];
    if (($context['user_phone'] ?? '') !== '') return (string)$context['user_phone'];
    $stmt = $pdo->prepare('SELECT id FROM web_users WHERE display_name=:name');
    $stmt->execute([':name'=>(string)($context['manager'] ?? '')]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? webUserPhone((int)$ids[0]) : '';
}

/** UTC intervals of constant business offset; does not depend on MariaDB timezone tables. */
function maxHistoryIntervals(string $from, string $to): array
{
    $zone = new DateTimeZone(MAX_HISTORY_TIMEZONE);
    $parse = static function (string $value) use ($zone): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') {
            throw new InvalidArgumentException('Invalid history date');
        }
        return $date;
    };
    $start = $parse($from);$end = $parse($to)->modify('+1 day');
    if ($end <= $start) throw new InvalidArgumentException('Invalid history range');
    $transitions = $zone->getTransitions($start->getTimestamp(), $end->getTimestamp());
    $result = [];
    foreach ($transitions as $index=>$transition) {
        $a = max($start->getTimestamp(), $transition['ts']);
        $b = $transitions[$index+1]['ts'] ?? $end->getTimestamp();
        if ($b > $a) $result[] = ['from'=>gmdate('Y-m-d H:i:s',$a),'to'=>gmdate('Y-m-d H:i:s',$b),'offset'=>(int)$transition['offset']];
    }
    return $result;
}

/** All filters use stored columns directly, retaining phone/time and owner/time index eligibility. */
function maxHistoryQuery(string $owner, array $phones, string $from, string $to): array
{
    if ($owner === '' || !$phones) return ['', []];
    if (count($phones) > 100) throw new InvalidArgumentException('Too many client phones');
    $selects=[];$params=[];
    foreach (maxHistoryIntervals($from,$to) as $i=>$interval) {
        $placeholders=[];
        foreach ($phones as $j=>$phone) {$key=":p{$i}_{$j}";$params[$key]=$phone;$placeholders[]=$key;}
        $params[":owner$i"]=$owner;$params[":start$i"]=$interval['from'];$params[":end$i"]=$interval['to'];$params[":offset$i"]=$interval['offset'];
        $selects[] = 'SELECT DATE(TIMESTAMPADD(SECOND,:offset'.$i.',occurred_at)) AS local_day,
            SUM(direction=\'incoming\') AS incoming_count, SUM(direction=\'outgoing\') AS outgoing_count,
            MAX(occurred_at) AS last_event_utc FROM max_messages
            WHERE user_phone=:owner'.$i.' AND phone IN ('.implode(',',$placeholders).')
            AND occurred_at>=:start'.$i.' AND occurred_at<:end'.$i.'
            AND direction IN (\'incoming\',\'outgoing\') GROUP BY local_day';
    }
    return [implode(' UNION ALL ', $selects),$params];
}

function maxHistoryDaily(PDO $pdo, string $owner, array $phones, string $from, string $to): array
{
    [$sql,$params]=maxHistoryQuery($owner,$phones,$from,$to);
    if ($sql === '') return [];
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$days=[];
    foreach ($stmt as $row) {
        $day=(string)$row['local_day'];
        $days[$day] ??= ['date'=>$day,'incoming_count'=>0,'outgoing_count'=>0,'last_event_at'=>''];
        $days[$day]['incoming_count']+=(int)$row['incoming_count'];
        $days[$day]['outgoing_count']+=(int)$row['outgoing_count'];
        $stamp=str_replace(' ','T',(string)$row['last_event_utc']).'Z';
        if ($stamp > $days[$day]['last_event_at']) $days[$day]['last_event_at']=$stamp;
    }
    ksort($days,SORT_STRING);
    return array_values($days);
}
