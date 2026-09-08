<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/config.php'; require_once dirname(__DIR__).'/api/web_auth.php';
if(PHP_SAPI!=='cli')exit(1);$login=normalizeWebLoginEmail((string)($argv[1]??''));$name=trim((string)($argv[2]??''));
fwrite(STDOUT,'PIN: ');if(function_exists('shell_exec'))@shell_exec('stty -echo');$pin=trim((string)fgets(STDIN));if(function_exists('shell_exec'))@shell_exec('stty echo');fwrite(STDOUT,"\n");if($login===''||$name===''||!preg_match('/^\d{4,12}$/',$pin))throw new RuntimeException('Использование: php scripts/create_first_web_admin.php EMAIL "ФИО" (PIN: 4–12 цифр)');
$pdo=getPdo();ensureWebAuthTables($pdo);if((int)$pdo->query("SELECT COUNT(*) FROM web_users WHERE role='admin'")->fetchColumn()>0)throw new RuntimeException('Администратор уже существует');
$pdo->prepare("INSERT INTO web_users(display_name,login,pin_hash,role,is_active) VALUES(:name,:login,:pin,'admin',1)")->execute([':name'=>$name,':login'=>strtolower($login),':pin'=>password_hash($pin,PASSWORD_DEFAULT)]);fwrite(STDOUT,"Администратор создан\n");
