<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/email_sync.php';

if (normalizeImapHost('ssl://imap.mail.ru:993/') !== 'imap.mail.ru') {
    throw new RuntimeException('IMAP host не очищается от схемы и порта');
}
if (imapConnectionFlags(['imap_ssl'=>1, 'imap_port'=>993]) !== '/imap/ssl/readonly') {
    throw new RuntimeException('Для порта 993 не выбран IMAP over SSL');
}
if (imapConnectionFlags(['imap_ssl'=>1, 'imap_port'=>143]) !== '/imap/tls/readonly') {
    throw new RuntimeException('Для порта 143 не выбран STARTTLS');
}
if (findImapFolder(['INBOX', 'Черновики', 'Отправленные'], 'Sent', 'outgoing') !== 'Отправленные') {
    throw new RuntimeException('Не определяется локализованная папка отправленных');
}
if (sentImapFolderCandidates(['INBOX', 'Sent', 'Отправленные', 'Черновики'], 'Sent') !== ['Sent', 'Отправленные']) {
    throw new RuntimeException('Не обнаруживаются все возможные папки отправленных писем');
}
if (sentImapFolderCandidates(['INBOX', 'Исходящие', 'Sent Items'], 'Sent') !== ['Исходящие', 'Sent Items']) {
    throw new RuntimeException('Не обнаруживаются альтернативные названия папки исходящих писем');
}
$singleParameter = (object)['attribute'=>'filename', 'value'=>'report.pdf'];
if (normalizeImapParameters($singleParameter) !== [$singleParameter]) {
    throw new RuntimeException('Одиночный stdClass параметр IMAP не нормализуется');
}
$parameterContainer = (object)['0'=>$singleParameter];
if (normalizeImapParameters($parameterContainer) !== [$singleParameter]) {
    throw new RuntimeException('Контейнер stdClass параметров IMAP не нормализуется');
}
$cp1251 = iconv('UTF-8', 'Windows-1251', 'Проверка кодировки');
if ($cp1251 === false || normalizeImapContentText($cp1251, 'windows-1251') !== 'Проверка кодировки') {
    throw new RuntimeException('Тело письма Windows-1251 не преобразуется в UTF-8');
}
if (preg_match('//u', normalizeImapContentText("Текст\xA7\xE5", '')) !== 1) {
    throw new RuntimeException('Некорректные байты тела письма попадают в базу данных');
}

$api = (string)file_get_contents($root . '/api/admin_email.php');
foreach (['action === \'test\'', 'testImapMailbox(', 'resolveEmailMailboxPassword('] as $required) {
    if (!str_contains($api, $required)) throw new RuntimeException("В API отсутствует проверка IMAP: {$required}");
}
foreach (["email_messages.direction = 'outgoing'", 'LOWER(email_messages.from_email) = LOWER(email_mailboxes.email)', "THEN 'outgoing' ELSE 'incoming' END AS direction", "COALESCE(NULLIF(email_messages.to_emails, ''), email_messages.client_email)"] as $required) {
    if (!str_contains($api, $required)) throw new RuntimeException("Реестр не распознаёт исходящее письмо менеджера: {$required}");
}

$sync = (string)file_get_contents($root . '/api/email_sync.php');
$syncCli = (string)file_get_contents($root . '/api/sync_email.php');
$cronInstaller = (string)file_get_contents($root . '/scripts/install_email_sync_cron.sh');
foreach (['OP_READONLY', 'FT_PEEK', 'fetchImapBodyWithoutMarkingRead', 'imap_fetch_overview($imap, (string)$uid, FT_UID)'] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("IMAP-синхронизация может пометить письмо прочитанным: {$required}");
}
foreach (['imap_setflag_full', 'imap_clearflag_full', 'imap_delete', 'imap_mail_move'] as $forbidden) {
    if (str_contains($sync, $forbidden)) throw new RuntimeException("IMAP-синхронизация изменяет почтовый ящик: {$forbidden}");
}
if (!str_contains($sync, 'newestImapFolder(') ||
    !str_contains($sync, "\$mailbox['sent_folder'], 'outgoing', \$messageErrors, \$limitPerMailbox") ||
    str_contains($sync, "\$mailbox['inbox_folder'], 'incoming', \$messageErrors")) {
    throw new RuntimeException('Сервис должен импортировать только исходящие письма');
}
foreach (['rsort($uids, SORT_NUMERIC)', 'catch (Throwable $e)', '$messageErrors[]', 'if ($limit > 0 && $imported >= $limit) break', 'array_fill_keys'] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("Ошибка одного старого письма может заблокировать загрузку новых: {$required}");
}
foreach (['discoverSentImapFolder($mailbox)', "str_contains(strtolower((string)\$mailbox['imap_host']), 'mail.ru')", 'отправленные(?: письма)?|исходящие', 'sent(?: items| messages| mail| objects)?'] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("Mail.ru не использует автоматическое определение папки исходящих: {$required}");
}
foreach (["int \$limit = 50", "'file_size'=>(int)(\$part->bytes ?? 0)", 'Загрузка бинарного тела', 'imap_timeout(IMAP_READTIMEOUT, 10)'] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("IMAP-синхронизация может превысить тайм-аут шлюза: {$required}");
}
foreach (['collectImapParts($imap, $number, $structure, \'\', $content, $attachments, false)', 'универсального значения Sent дополнительно сверяем список папок', 'медленная загрузка MIME-тел'] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("HTTP-синхронизация всё ещё выполняет долгие IMAP-операции: {$required}");
}
foreach (['normalizeImapContentText($body, $charset)', "if (\$attribute === 'charset')"] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("Тело IMAP-письма не нормализуется перед записью: {$required}");
}
foreach (['syncEmailMailboxes(getPdo(), null, 0)', 'flock($lock, LOCK_EX | LOCK_NB)'] as $required) {
    if (!str_contains($syncCli, $required)) throw new RuntimeException("CLI не выполняет безопасную полную синхронизацию: {$required}");
}
foreach (['7 * * * *', 'scripts/sync_email_cron.sh', 'CALLTRACK_EMAIL_SYNC'] as $required) {
    if (!str_contains($cronInstaller, $required)) throw new RuntimeException("Не настроена ежечасная синхронизация исходящих писем: {$required}");
}
if (str_contains($api, 'LIMIT 1000')) throw new RuntimeException('Реестр по-прежнему обрезает историю исходящих писем');
if (!str_contains($api, 'nextEmailMailboxId($pdo)') || !str_contains($api, 'syncEmailMailboxes($pdo, $mailboxId, 10)')) {
    throw new RuntimeException('Ручная синхронизация может снова превысить HTTP-тайм-аут');
}
foreach (['executeEmailMessageInsert(', "str_contains(\$error->getMessage(), 'Incorrect string value')", "\$messageData[':body_text'] = ''", "\$messageData[':body_html'] = ''"] as $required) {
    if (!str_contains($sync, $required)) throw new RuntimeException("Ошибка кодировки тела всё ещё блокирует импорт письма: {$required}");
}

$html = (string)file_get_contents($root . '/analizmop/index.html');
$js = (string)file_get_contents($root . '/analizmop/api.js');
foreach (['emailTestConnectionBtn', 'Проверить подключение', 'testEmailConnection'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("В интерфейсе отсутствует проверка IMAP: {$required}");
}
if (!str_contains($js, 'action=test')) throw new RuntimeException('Клиент не вызывает IMAP test endpoint');
foreach (['emailSyncNewBtn', 'Подгрузить новые письма', 'emailSyncProgress', 'Подгружаем новые письма', 'syncErrors.slice(0,2)', 'catch(error){syncError=error;}', 'Не удалось завершить текущую порцию синхронизации', 'Писем в реестре:'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("В реестре нет управления или прогресса синхронизации: {$required}");
}
foreach (["direction:'outgoing'", 'loadEmailRegistryClientNames(emailMessages)', 'window.calltrackApi.lookupClientNames([],emails.slice(offset,offset+500))', 'item.client_display_name', '<th>Менеджер</th><th>Клиент</th>', 'item.manager_name'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("Реестр исходящих email не показывает менеджера или клиента из Clients: {$required}");
}

echo "email_imap_settings_test: OK\n";
