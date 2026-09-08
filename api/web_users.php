<?php
declare(strict_types=1);
require_once __DIR__.'/config.php'; require_once __DIR__.'/web_auth.php';
try {
    $pdo=getPdo(); ensureWebAuthTables($pdo); ensureUserTelemetryTables($pdo); requireWebAdmin($pdo);
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $users=$pdo->query('SELECT id,display_name,login,role,manager_user_phone,is_active,last_login_at,created_at,updated_at FROM web_users ORDER BY display_name')->fetchAll();
        $managers=$pdo->query("SELECT user_phone,MAX(manager) manager FROM (SELECT user_phone,manager FROM app_user_reports UNION ALL SELECT user_phone,manager FROM app_user_states) m WHERE user_phone<>'' GROUP BY user_phone ORDER BY manager")->fetchAll();
        sendJson(['status'=>'success','data'=>$users,'managers'=>$managers]);
    }
    if ($_SERVER['REQUEST_METHOD']==='DELETE') {
        $data=readJsonBody();$id=(int)($data['id']??0);$current=requireWebAdmin($pdo);
        if($id<=0)sendJson(['status'=>'error','message'=>'Не указан пользователь'],400);
        if($id===(int)$current['id'])sendJson(['status'=>'error','message'=>'Нельзя удалить текущего пользователя'],400);
        $stmt=$pdo->prepare('DELETE FROM web_users WHERE id=:id');$stmt->execute([':id'=>$id]);
        if($stmt->rowCount()===0)sendJson(['status'=>'error','message'=>'Пользователь не найден'],404);
        sendJson(['status'=>'success']);
    }
    $data=readJsonBody();$id=(int)($data['id']??0);$role=(string)($data['role']??'');$manager=trim((string)($data['manager_user_phone']??''));
    if (!in_array($role,['admin','manager'],true)) sendJson(['status'=>'error','message'=>'Допустимы только роли admin и manager'],400);
    if ($role==='manager'&&$manager==='') sendJson(['status'=>'error','message'=>'Для менеджера обязательна связь с менеджером Calltrack'],400);
    if($role==='manager'){$check=$pdo->prepare("SELECT 1 FROM (SELECT user_phone FROM app_user_reports UNION SELECT user_phone FROM app_user_states) managers WHERE user_phone=:phone LIMIT 1");$check->execute([':phone'=>$manager]);if(!$check->fetchColumn())sendJson(['status'=>'error','message'=>'Выбранный менеджер Calltrack не найден'],400);}
    $fields=[':name'=>trim((string)($data['display_name']??'')),':login'=>normalizeWebLoginEmail((string)($data['login']??'')),':role'=>$role,':manager'=>$role==='manager'?$manager:null,':active'=>!empty($data['is_active'])?1:0];
    if ($fields[':name']===''||$fields[':login']==='') sendJson(['status'=>'error','message'=>'Заполните ФИО и корректный email'],400);
    $pin=(string)($data['pin']??'');
    if($pin!==''&&!preg_match('/^\d{4,12}$/',$pin))sendJson(['status'=>'error','message'=>'PIN должен содержать от 4 до 12 цифр'],400);
    if ($id>0) {
        $sql='UPDATE web_users SET display_name=:name,login=:login,role=:role,manager_user_phone=:manager,is_active=:active';
        if($pin!==''){$sql.=',pin_hash=:pin';$fields[':pin']=password_hash($pin,PASSWORD_DEFAULT);}$fields[':id']=$id;$pdo->prepare($sql.' WHERE id=:id')->execute($fields);
    } else {
        if($pin==='')sendJson(['status'=>'error','message'=>'Укажите PIN-код'],400);$fields[':pin']=password_hash($pin,PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO web_users(display_name,login,pin_hash,role,manager_user_phone,is_active) VALUES(:name,:login,:pin,:role,:manager,:active)')->execute($fields);
    }
    if($pin!=='')$pdo->prepare('DELETE FROM web_login_attempts WHERE login=:login')->execute([':login'=>$fields[':login']]);
    sendJson(['status'=>'success']);
} catch(Throwable $e){sendJson(['status'=>'error','message'=>$e->getMessage()],500);}
