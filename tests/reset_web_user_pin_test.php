<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/reset_web_user_pin.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE web_users (id INTEGER PRIMARY KEY, login TEXT UNIQUE, pin_hash TEXT, display_name TEXT, role TEXT, manager_user_phone TEXT, is_active INTEGER)');
$oldHash = password_hash('1111', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO web_users VALUES (1, :login, :hash, :name, :role, :manager, 0)')->execute([':login'=>'admin', ':hash'=>$oldHash, ':name'=>'Администратор', ':role'=>'admin', ':manager'=>'manager-1']);

if (findWebUserForPinReset($pdo, 'unknown') !== null) throw new RuntimeException('Неизвестный login не отклонён');
try { resetWebUserPin($pdo, 1, '12ab'); throw new RuntimeException('Некорректный PIN принят'); }
catch (InvalidArgumentException $expected) {}
resetWebUserPin($pdo, 1, '987654');
$row = $pdo->query('SELECT * FROM web_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if (!password_verify('987654', (string)$row['pin_hash']) || password_verify('1111', (string)$row['pin_hash'])) throw new RuntimeException('Hash нового PIN не прошёл password_verify');
if ($row['login'] !== 'admin' || $row['display_name'] !== 'Администратор' || $row['role'] !== 'admin' || $row['manager_user_phone'] !== 'manager-1' || (int)$row['is_active'] !== 0) throw new RuntimeException('Сброс PIN изменил другие поля пользователя');
echo "reset_web_user_pin_test: OK\n";
