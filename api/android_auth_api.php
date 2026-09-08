<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/android_auth.php';
try{
    $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';if(!$https)sendJson(['status'=>'error','message'=>'Требуется HTTPS'],400);
    $pdo=getPdo();ensureAndroidAuthTables($pdo);$action=(string)($_GET['action']??'me');
    if($action==='login'){
        $data=readJsonBody();$login=normalizeWebLoginEmail((string)($data['login']??''));$pin=(string)($data['pin']??'');
        if($login===''||!preg_match('/^\d{4,12}$/',$pin))sendJson(['status'=>'error','message'=>'Неверный email или PIN-код'],401);
        $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));$attempt=$pdo->prepare('SELECT COUNT(*) FROM web_login_attempts WHERE login=:login AND ip_hash=:ip AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$attempt->execute([':login'=>$login,':ip'=>$ip]);
        if((int)$attempt->fetchColumn()>=5)sendJson(['status'=>'error','message'=>'Слишком много попыток. Повторите через 15 минут'],429);
        $stmt=$pdo->prepare('SELECT id,display_name,login,pin_hash,role,manager_user_phone,is_active FROM web_users WHERE login=:login LIMIT 1');$stmt->execute([':login'=>$login]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$user||(int)$user['is_active']!==1||!password_verify($pin,(string)$user['pin_hash'])){$pdo->prepare('INSERT INTO web_login_attempts(login,ip_hash)VALUES(:login,:ip)')->execute([':login'=>$login,':ip'=>$ip]);sendJson(['status'=>'error','message'=>'Неверный email или PIN-код'],401);}
        $raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$pdo->prepare('INSERT INTO web_user_tokens(web_user_id,token_hash,device_name,expires_at)VALUES(:id,:hash,:device,DATE_ADD(NOW(),INTERVAL 30 DAY))')->execute([':id'=>$user['id'],':hash'=>hash('sha256',$raw),':device'=>mb_substr(trim((string)($data['device_name']??'')),0,190)]);
        $pdo->prepare('DELETE FROM web_login_attempts WHERE login=:login AND ip_hash=:ip')->execute([':login'=>$login,':ip'=>$ip]);$pdo->prepare('UPDATE web_users SET last_login_at=NOW() WHERE id=:id')->execute([':id'=>$user['id']]);
        unset($user['pin_hash'],$user['is_active']);sendJson(['status'=>'success','data'=>['token'=>$raw,'user'=>$user]]);
    }
    $user=currentAndroidUser($pdo);if(!$user)sendJson(['status'=>'error','message'=>'Требуется авторизация'],401);
    if($action==='logout'){$pdo->prepare('UPDATE web_user_tokens SET revoked_at=NOW() WHERE token_hash=:hash')->execute([':hash'=>hash('sha256',bearerToken())]);sendJson(['status'=>'success']);}
    sendJson(['status'=>'success','data'=>['user'=>$user]]);
}catch(Throwable $e){sendJson(['status'=>'error','message'=>$e->getMessage()],500);}
