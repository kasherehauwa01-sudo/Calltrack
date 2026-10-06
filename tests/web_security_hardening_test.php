<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/api/web_auth.php';
$auth=(string)file_get_contents($root.'/api/web_auth.php');
$authApi=(string)file_get_contents($root.'/api/web_auth_api.php');
$androidAuth=(string)file_get_contents($root.'/api/android_auth_api.php');
$frontend=(string)file_get_contents($root.'/analizmop/api.js').(string)file_get_contents($root.'/analizmop/index.html');
$config=(string)file_get_contents($root.'/api/config.php');

function securityAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

foreach (['ADMIN_PASSWORD','CALLTRACK_ADMIN_PASSWORD','X-Calltrack-Admin-Password'] as $forbidden) {
    securityAssert(!str_contains($frontend.$config,$forbidden),"Остался общий административный секрет: {$forbidden}");
}
foreach (['session.use_strict_mode','httponly','samesite','session_regenerate_id(true)','created_at','last_seen_at','destroyWebSession','random_bytes(32)','HTTP_X_CSRF_TOKEN','hash_equals'] as $required) {
    securityAssert(str_contains($auth.$authApi,$required),"Web session security не содержит: {$required}");
}
securityAssert(strpos($authApi,'sendJson([\'status\'=>\'error\',\'message\'=>\'Неверный email или PIN-код\']')<strpos($authApi,'session_regenerate_id(true)'),'Неправильный PIN может дойти до создания сессии');
securityAssert(isValidWebCsrfToken('correct-token','correct-token'),'Правильный CSRF-токен отклонён');
securityAssert(!isValidWebCsrfToken('correct-token','wrong-token'),'Неправильный CSRF-токен принят');
securityAssert(!isValidWebCsrfToken('',''),'Пустой CSRF-токен принят');
foreach (['web_users.php','delete_calls.php','delete_personal_contacts.php','admin_updates.php','admin_install_update.php','admin_clients_cache.php','get_users.php','user_command.php'] as $file) {
    $source=(string)file_get_contents($root.'/api/'.$file);
    securityAssert(str_contains($source,'requireWebAdmin('),"Admin endpoint без backend RBAC: {$file}");
}
$users=(string)file_get_contents($root.'/api/web_users.php');
securityAssert(str_contains($users,'SELECT id,display_name,login,role,is_active,last_login_at,created_at,updated_at'),'web_users должен возвращать явный безопасный список полей');
securityAssert(!preg_match('/SELECT[^;]+pin_hash[^;]+FROM web_users/is',$users),'web_users API возвращает pin_hash');
$email=(string)file_get_contents($root.'/api/admin_email.php');
securityAssert(!preg_match('/sendJson[^;]+password_encrypted/is',$email),'Email API возвращает password_encrypted');
foreach (['password_verify','password_needs_rehash','token_hash',"hash('sha256',\$raw)"] as $required) {
    securityAssert(str_contains($authApi.$androidAuth,$required),"Auth hardening не содержит: {$required}");
}
foreach (['Strict-Transport-Security','X-Content-Type-Options: nosniff','Referrer-Policy: same-origin','X-Frame-Options: DENY',"frame-ancestors 'none'",'Permissions-Policy:'] as $required) {
    securityAssert(str_contains($config,$required),"Security header отсутствует: {$required}");
}
securityAssert(str_contains($auth,"\$user['role'] !== 'admin'"),'Admin RBAC допускает роль, отличную от admin');
securityAssert(str_contains($frontend,"'X-CSRF-Token'"),'Frontend не отправляет CSRF-токен');
securityAssert(str_contains($authApi,"action==='logout'")&&str_contains($authApi,"REQUEST_METHOD")&&str_contains($authApi,"'POST'"),'Logout не переведён на POST');

echo "web_security_hardening_test: OK\n";
