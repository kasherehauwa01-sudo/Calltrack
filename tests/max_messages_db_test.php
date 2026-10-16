<?php
declare(strict_types=1);
// Explicitly opt in against an isolated development database; never production.
$dsn = getenv('MAX_SYNC_TEST_DSN') ?: '';
if (!str_ends_with($dsn, 'dbname=calltrack_max_sync_test')) { echo "max_messages_db_test: SKIP (isolated DSN required)\n"; exit(0); }
require_once dirname(__DIR__) . '/api/android_auth.php';
require_once dirname(__DIR__) . '/api/max_messages_service.php';
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/20261016_create_max_messages.sql'));
ensureAndroidAuthTables($pdo);
function dbCheck(bool $test, string $message): void { if (!$test) throw new RuntimeException($message); }
$suffix=bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO web_users(display_name,login,pin_hash,role) VALUES (?,?,?,'manager')")->execute(['Trusted Manager','max-sync-'.$suffix,'not-a-login-hash']);
$userId=(int)$pdo->lastInsertId();
$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$pdo->prepare('INSERT INTO web_user_tokens(web_user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$userId,hash('sha256',$token)]);
$sample=['source_event_id'=>'db-test-'.$suffix,'direction'=>'incoming','contact_name'=>"Name'); DROP TABLE calls; --",'phone'=>'','max_user_id'=>'123','shortcut_id'=>'456','occurred_at'=>time()*1000,'manager'=>'FORGED','user_phone'=>'FORGED'];
$handle=static fn(array $rows)=>handleMaxMessages('POST',true,json_encode(['messages'=>$rows]),static fn()=>currentAndroidUser($pdo),'androidManagerIdentity',static fn($messages,$identity)=>storeMaxMessages($pdo,$messages,$identity));
try {
    unset($_SERVER['HTTP_AUTHORIZATION']);dbCheck($handle([$sample])[0]===401,'no auth');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('x',43);dbCheck($handle([$sample])[0]===401,'invalid token');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
    foreach (['incoming','outgoing'] as $direction) {
        $row=array_replace($sample,['direction'=>$direction,'source_event_id'=>$sample['source_event_id'].'-'.$direction]);
        [$code,$response]=$handle([$row]);dbCheck($code===200 && $response['accepted']===[$row['source_event_id']],'valid auth and accepted');
        dbCheck($handle([$row])[0]===200,'duplicate success');
    }
    $stmt=$pdo->prepare('SELECT * FROM max_messages WHERE user_phone=?');$stmt->execute([webUserPhone($userId)]);$saved=$stmt->fetchAll(PDO::FETCH_ASSOC);
    dbCheck(count($saved)===2,'idempotent MariaDB');
    foreach ($saved as $row) {
        dbCheck($row['contact_name']===$sample['contact_name'],'SQL injection stored literally');
        dbCheck($row['manager']==='Trusted Manager' && $row['user_phone']===webUserPhone($userId),'server-owned identity');
        dbCheck(!array_key_exists('message_text',$row),'no message body column');
    }
    $badBatch=[$sample,array_replace($sample,['direction'=>'invalid'])];
    dbCheck($handle($badBatch)[0]===400,'whole batch validation');
    $count=$pdo->prepare('SELECT COUNT(*) FROM max_messages WHERE source_event_id=?');$count->execute([$sample['source_event_id']]);dbCheck((int)$count->fetchColumn()===0,'no partial insert');
    // A collision owned by a different account must not be acknowledged or overwritten.
    $other=['manager'=>'Other','user_phone'=>'web-user-other'];
    storeMaxMessages($pdo,validateMaxMessages(['messages'=>[$sample]]),$other);
    dbCheck($handle([$sample])[0]===500,'cross-account conflict');
    $count->execute([$sample['source_event_id']]);dbCheck((int)$count->fetchColumn()===1,'conflict does not overwrite');
    $httpUrl = getenv('MAX_SYNC_HTTP_TEST_URL') ?: '';
    if ($httpUrl !== '') {
        dbCheck(str_starts_with($httpUrl, 'http://127.0.0.1:'), 'local test endpoint only');
        $request = static function (?string $bearer, string $method = 'POST', bool $https = true) use ($httpUrl, $sample): array {
            $curl = curl_init($httpUrl);
            $headers = ['Content-Type: application/json'];
            if ($https) $headers[] = 'X-Forwarded-Proto: https';
            if ($bearer !== null) $headers[] = 'Authorization: Bearer '.$bearer;
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_POSTFIELDS=>json_encode(['messages'=>[array_replace($sample,['source_event_id'=>$sample['source_event_id'].'-http'])]]),
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
            $body=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            return [$code,json_decode((string)$body,true)];
        };
        dbCheck($request(null)[0]===401,'HTTP no auth');
        dbCheck($request(str_repeat('x',43))[0]===401,'HTTP invalid token');
        dbCheck($request($token,'GET')[0]===405,'HTTP method');
        dbCheck($request($token,'POST',false)[0]===400,'HTTP HTTPS');
        [$code,$body]=$request($token);dbCheck($code===200 && $body['accepted']===[$sample['source_event_id'].'-http'],'HTTP accepted IDs');
        dbCheck($request($token)[0]===200,'HTTP duplicate');
    }
    echo "max_messages_db_test: OK\n";
} finally {
    $pdo->prepare('DELETE FROM max_messages WHERE source_event_id LIKE ?')->execute(['db-test-'.$suffix.'%']);
    $pdo->prepare('DELETE FROM web_users WHERE id=?')->execute([$userId]);
    unset($_SERVER['HTTP_AUTHORIZATION']);
}
