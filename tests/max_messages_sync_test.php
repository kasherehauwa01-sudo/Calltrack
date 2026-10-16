<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/max_messages_service.php';
function checkMax(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$sample = ['source_event_id'=>'test-in-1','direction'=>'incoming','contact_name'=>"Name'); DROP TABLE calls; --",'phone'=>'','max_user_id'=>'144673255','shortcut_id'=>'166503071','occurred_at'=>time()*1000];
$seen = [];
$auth = static fn() => ['id'=>7,'display_name'=>'Trusted'];
$identity = static fn(array $user) => ['manager'=>$user['display_name'],'user_phone'=>'web:7'];
$persist = static function (array $rows, array $who) use (&$seen): array {
    checkMax($who === ['manager'=>'Trusted','user_phone'=>'web:7'], 'auth identity');
    foreach ($rows as $row) $seen[$row['source_event_id']] = $row;
    return array_column($rows, 'source_event_id');
};
$call = static fn(array $messages, ?callable $authenticate = null) => handleMaxMessages('POST', true, json_encode(['messages'=>$messages]), $authenticate ?? $auth, $identity, $persist);
checkMax($call([$sample], static fn()=>null)[0] === 401, 'no/invalid auth');
foreach (['incoming','outgoing'] as $direction) {
    $row = array_replace($sample, ['direction'=>$direction,'manager'=>'FORGED','user_phone'=>'FORGED']);
    [$code,$body]=$call([$row]);checkMax($code===200 && $body['accepted']===['test-in-1'], 'accepted ids');
}
foreach ([['direction'=>'bad'], ['source_event_id'=>''], ['occurred_at'=>'yesterday'], ['occurred_at'=>1.2], ['message_text'=>'private'], ['is_group_conversation'=>true], ['contact_name'=>str_repeat('x',256)]] as $changes) {
    checkMax($call([array_replace($sample,$changes)])[0]===400, 'validation');
}
$row=$sample;unset($row['source_event_id']);checkMax($call([$row])[0]===400,'missing id');
checkMax($call(array_fill(0,101,$sample))[0]===400,'batch limit');
$call([$sample]);$call([$sample]);checkMax(count($seen)===1,'idempotent contract');
checkMax(handleMaxMessages('GET',true,'',$auth,$identity,$persist)[0]===405,'method');
checkMax(handleMaxMessages('POST',false,'',$auth,$identity,$persist)[0]===400,'https');
checkMax(handleMaxMessages('POST',true,str_repeat('x',262145),$auth,$identity,$persist)[0]===413,'body limit');
checkMax(handleMaxMessages('POST',true,'{',$auth,$identity,$persist)[0]===400,'invalid json');
$failure=handleMaxMessages('POST',true,json_encode(['messages'=>[$sample]]),$auth,$identity,static function(){throw new RuntimeException('SECRET SQL');});
checkMax($failure[0]===500 && !str_contains(json_encode($failure),'SECRET'),'safe server error');
echo "max_messages_sync_test: OK\n";
