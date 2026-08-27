<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/email_sync.php';

if (normalizeImapHost('ssl://imap.mail.ru:993/') !== 'imap.mail.ru') {
    throw new RuntimeException('IMAP host не очищается от схемы и порта');
}
if (imapConnectionFlags(['imap_ssl'=>1, 'imap_port'=>993]) !== '/imap/ssl') {
    throw new RuntimeException('Для порта 993 не выбран IMAP over SSL');
}
if (imapConnectionFlags(['imap_ssl'=>1, 'imap_port'=>143]) !== '/imap/tls') {
    throw new RuntimeException('Для порта 143 не выбран STARTTLS');
}
if (findImapFolder(['INBOX', 'Черновики', 'Отправленные'], 'Sent', 'outgoing') !== 'Отправленные') {
    throw new RuntimeException('Не определяется локализованная папка отправленных');
}

$api = (string)file_get_contents($root . '/api/admin_email.php');
foreach (['action === \'test\'', 'testImapMailbox(', 'resolveEmailMailboxPassword('] as $required) {
    if (!str_contains($api, $required)) throw new RuntimeException("В API отсутствует проверка IMAP: {$required}");
}

$html = (string)file_get_contents($root . '/analizmop/index.html');
$js = (string)file_get_contents($root . '/analizmop/api.js');
foreach (['emailTestConnectionBtn', 'Проверить подключение', 'testEmailConnection'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("В интерфейсе отсутствует проверка IMAP: {$required}");
}
if (!str_contains($js, 'action=test')) throw new RuntimeException('Клиент не вызывает IMAP test endpoint');

echo "email_imap_settings_test: OK\n";
