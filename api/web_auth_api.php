<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/web_auth.php';

try {
    $pdo=getPdo(); ensureWebAuthTables($pdo); startWebSession();
    $action=(string)($_GET['action'] ?? 'me');
    if ($action==='logout') { $_SESSION=[]; if (ini_get('session.use_cookies')) setcookie(session_name(),'',time()-42000,'/'); session_destroy(); sendJson(['status'=>'success']); }
    if ($action==='login') {
        $data=readJsonBody(); $login=normalizeWebLoginEmail((string)($data['login']??'')); $pin=(string)($data['pin']??'');
        if($login===''||!preg_match('/^\d{4,12}$/',$pin))sendJson(['status'=>'error','message'=>'Неверный email или PIN-код. Используйте PIN web-пользователя, а не пароль почтового ящика'],401);
        $ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
        $attempt=$pdo->prepare('SELECT COUNT(*) FROM web_login_attempts WHERE login=:login AND ip_hash=:ip AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $attempt->execute([':login'=>$login,':ip'=>$ipHash]);
        if ((int)$attempt->fetchColumn()>=5) sendJson(['status'=>'error','message'=>'Слишком много попыток. Повторите через 15 минут'],429);
        $stmt=$pdo->prepare('SELECT id,pin_hash,is_active FROM web_users WHERE login=:login LIMIT 1');$stmt->execute([':login'=>$login]);$row=$stmt->fetch();
        if (!$row || !(int)$row['is_active'] || !password_verify($pin,(string)$row['pin_hash'])) {
            $pdo->prepare('INSERT INTO web_login_attempts(login,ip_hash) VALUES(:login,:ip)')->execute([':login'=>$login,':ip'=>$ipHash]);
            sendJson(['status'=>'error','message'=>'Неверный email или PIN-код. Используйте PIN web-пользователя, а не пароль почтового ящика'],401);
        }
        session_regenerate_id(true); $_SESSION['web_user_id']=(int)$row['id'];
        $pdo->prepare('UPDATE web_users SET last_login_at=NOW() WHERE id=:id')->execute([':id'=>$row['id']]);
        $pdo->prepare('DELETE FROM web_login_attempts WHERE login=:login AND ip_hash=:ip')->execute([':login'=>$login,':ip'=>$ipHash]);
    }
    sendJson(['status'=>'success','data'=>requireWebUser($pdo)]);
} catch(Throwable $e){sendJson(['status'=>'error','message'=>$e->getMessage()],500);}
