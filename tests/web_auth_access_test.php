<?php
declare(strict_types=1);
$root=dirname(__DIR__);$auth=file_get_contents($root.'/api/web_auth.php');$api=file_get_contents($root.'/api/web_auth_api.php');$users=file_get_contents($root.'/api/web_users.php');$html=file_get_contents($root.'/analizmop/index.html');
require_once $root.'/api/web_auth.php';
if(normalizeWebLoginEmail(' Manager@Example.COM ')!=='manager@example.com'||normalizeWebLoginEmail('manager-login')!=='')throw new RuntimeException('Email web-пользователя нормализуется неверно');
foreach (["ENUM('admin','manager')",'pin_hash VARCHAR(255)','manager_user_phone VARCHAR(30)','password_verify($pin','password_hash($pin','session_regenerate_id(true)',"'httponly'=>true","'samesite'=>'Lax'",'requireWebAdmin(PDO $pdo)','webManagerScope(array $user)','normalizeWebLoginEmail','FILTER_VALIDATE_EMAIL','login VARCHAR(254)'] as $required) if(!str_contains($auth.$api.$users,$required))throw new RuntimeException("Авторизация не содержит: $required");
foreach (['authScreen','authLogin','type="email"','authPin','logoutBtn',"user.role!=='admin'",'data-tab="admin"','webUserRole','webUserManager','Администратор','Менеджер'] as $required)if(!str_contains($html,$required))throw new RuntimeException("Web-интерфейс не содержит: $required");
$scoped=['get_calls.php','dashboard.php','update_call.php'];foreach($scoped as $file){$source=file_get_contents($root.'/api/'.$file);if(!str_contains($source,'webManagerScope('))throw new RuntimeException("Нет manager scope: $file");}
$admin=['admin_updates.php','admin_install_update.php','admin_clients_cache.php','get_users.php','user_command.php','delete_calls.php','delete_personal_contacts.php','web_users.php'];foreach($admin as $file){$source=file_get_contents($root.'/api/'.$file);if(!str_contains($source,'requireWebAdmin('))throw new RuntimeException("Нет admin guard: $file");}
foreach (['add_call.php','get_history.php','personal_contact.php','user_report.php','user_command_done.php','update.php'] as $android){$source=file_get_contents($root.'/api/'.$android);if(str_contains($source,'requireWebUser('))throw new RuntimeException("Android endpoint заблокирован web-сессией: $android");}
echo "web_auth_access_test: OK\n";
