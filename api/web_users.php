<?php
declare(strict_types=1);
require_once __DIR__.'/config.php'; require_once __DIR__.'/android_auth.php';
try {
    $pdo=getPdo(); ensureWebAuthTables($pdo); ensureUserTelemetryTables($pdo); requireWebAdmin($pdo);
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $users=$pdo->query('SELECT id,display_name,login,role,is_active,last_login_at,created_at,updated_at FROM web_users ORDER BY display_name')->fetchAll();
        sendJson(['status'=>'success','data'=>$users]);
    }
    if ($_SERVER['REQUEST_METHOD']==='DELETE') {
        $data=readJsonBody();$id=(int)($data['id']??0);$current=requireWebAdmin($pdo);
        if($id<=0)sendJson(['status'=>'error','message'=>'Не указан пользователь'],400);
        if($id===(int)$current['id'])sendJson(['status'=>'error','message'=>'Нельзя удалить текущего пользователя'],400);
        $target=$pdo->prepare('SELECT role,is_active FROM web_users WHERE id=:id');$target->execute([':id'=>$id]);$target=$target->fetch(PDO::FETCH_ASSOC);
        if($target&&$target['role']==='admin'&&(int)$target['is_active']===1&&(int)$pdo->query("SELECT COUNT(*) FROM web_users WHERE role='admin' AND is_active=1")->fetchColumn()<=1)sendJson(['status'=>'error','message'=>'Нельзя удалить последнего активного администратора'],400);
        $stmt=$pdo->prepare('DELETE FROM web_users WHERE id=:id');$stmt->execute([':id'=>$id]);
        if($stmt->rowCount()===0)sendJson(['status'=>'error','message'=>'Пользователь не найден'],404);
        sendJson(['status'=>'success']);
    }
    $data=readJsonBody();$id=(int)($data['id']??0);$role=(string)($data['role']??'');
    if (!in_array($role,['admin','manager'],true)) sendJson(['status'=>'error','message'=>'Допустимы только роли admin и manager'],400);
    $fields=[':name'=>trim((string)($data['display_name']??'')),':login'=>normalizeWebLoginEmail((string)($data['login']??'')),':role'=>$role,':active'=>!empty($data['is_active'])?1:0];
    if ($fields[':name']===''||$fields[':login']==='') sendJson(['status'=>'error','message'=>'Заполните ФИО и корректный email'],400);
    $pin=(string)($data['pin']??'');
    if($pin!==''&&!preg_match('/^\d{4,12}$/',$pin))sendJson(['status'=>'error','message'=>'PIN должен содержать от 4 до 12 цифр'],400);
    if ($id>0) {
        $target=$pdo->prepare('SELECT role,is_active FROM web_users WHERE id=:id');$target->execute([':id'=>$id]);$target=$target->fetch(PDO::FETCH_ASSOC);if(!$target)sendJson(['status'=>'error','message'=>'Пользователь не найден'],404);
        if($target['role']==='admin'&&(int)$target['is_active']===1&&($role!=='admin'||$fields[':active']!==1)&&(int)$pdo->query("SELECT COUNT(*) FROM web_users WHERE role='admin' AND is_active=1")->fetchColumn()<=1)sendJson(['status'=>'error','message'=>'Нельзя изменить роль или отключить последнего активного администратора'],400);
        $sql='UPDATE web_users SET display_name=:name,login=:login,role=:role,is_active=:active';
        if($pin!==''){$sql.=',pin_hash=:pin';$fields[':pin']=password_hash($pin,PASSWORD_DEFAULT);}$fields[':id']=$id;$pdo->prepare($sql.' WHERE id=:id')->execute($fields);
        if($pin!==''||$role!==$target['role']||$fields[':active']!==(int)$target['is_active'])revokeAndroidTokens($pdo,$id);
    } else {
        if($pin==='')sendJson(['status'=>'error','message'=>'Укажите PIN-код'],400);$fields[':pin']=password_hash($pin,PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO web_users(display_name,login,pin_hash,role,is_active) VALUES(:name,:login,:pin,:role,:active)')->execute($fields);
    }
    if($pin!=='')$pdo->prepare('DELETE FROM web_login_attempts WHERE login=:login')->execute([':login'=>$fields[':login']]);
    sendJson(['status'=>'success']);
} catch(Throwable $e){sendJson(['status'=>'error','message'=>$e->getMessage()],500);}
