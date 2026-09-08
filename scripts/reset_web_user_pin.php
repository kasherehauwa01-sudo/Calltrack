<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/config.php';
require_once dirname(__DIR__).'/api/web_auth.php';

if(PHP_SAPI!=='cli')exit(1);
$email=normalizeWebLoginEmail((string)($argv[1]??''));
if($email==='')throw new RuntimeException('Использование: php scripts/reset_web_user_pin.php EMAIL');
fwrite(STDOUT,'Новый PIN (4–12 цифр): ');
if(function_exists('shell_exec'))@shell_exec('stty -echo');
$pin=trim((string)fgets(STDIN));
if(function_exists('shell_exec'))@shell_exec('stty echo');
fwrite(STDOUT,"\n");
if(!preg_match('/^\d{4,12}$/',$pin))throw new RuntimeException('PIN должен содержать от 4 до 12 цифр');
$pdo=getPdo();ensureWebAuthTables($pdo);
$stmt=$pdo->prepare('UPDATE web_users SET pin_hash=:pin,is_active=1 WHERE login=:login');
$stmt->execute([':pin'=>password_hash($pin,PASSWORD_DEFAULT),':login'=>$email]);
if($stmt->rowCount()!==1)throw new RuntimeException('Web-пользователь с таким email не найден');
$pdo->prepare('DELETE FROM web_login_attempts WHERE login=:login')->execute([':login'=>$email]);
fwrite(STDOUT,"PIN обновлён, пользователь активирован, блокировка попыток снята\n");
