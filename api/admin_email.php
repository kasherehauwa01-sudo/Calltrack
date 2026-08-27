<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_sync.php';

function sendEmailSettingsPayload(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $stmt = $pdo->query('SELECT id, manager_name, email, imap_host, imap_port, imap_ssl, username, inbox_folder, sent_folder, enabled, last_sync_at, sync_status, sync_error, created_at, updated_at FROM email_mailboxes ORDER BY manager_name, email');
    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

function sendEmailRegistryPayload(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $where = [];
    $params = [];
    foreach (['manager' => 'email_messages.manager_name', 'direction' => 'email_messages.direction', 'client_status' => 'email_messages.client_status'] as $param => $column) {
        $value = trim((string)($_GET[$param] ?? ''));
        if ($value !== '') {
            $where[] = "$column = :$param";
            $params[":$param"] = $value;
        }
    }
    if (!empty($_GET['has_attachments'])) {
        $where[] = 'email_messages.has_attachments = :has_attachments';
        $params[':has_attachments'] = (int)$_GET['has_attachments'] === 1 ? 1 : 0;
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'email_messages.sent_at >= :date_from';
        $params[':date_from'] = normalizeDate((string)$_GET['date_from']) . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'email_messages.sent_at <= :date_to';
        $params[':date_to'] = normalizeDate((string)$_GET['date_to']) . ' 23:59:59';
    }
    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(email_messages.subject LIKE :search OR email_messages.client_email LIKE :search OR email_messages.client_name LIKE :search OR email_messages.body_text LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    $sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $pdo->prepare('SELECT email_messages.id, email_messages.sent_at, email_messages.manager_name, email_mailboxes.email AS manager_email, email_messages.direction, email_messages.client_name, email_messages.client_email, email_messages.subject, email_messages.client_status, email_messages.incoming_status, email_messages.outgoing_status, email_messages.message_size, email_messages.has_attachments, email_messages.attachment_count, email_messages.imap_uid FROM email_messages LEFT JOIN email_mailboxes ON email_mailboxes.id = email_messages.mailbox_id' . $sqlWhere . ' ORDER BY email_messages.sent_at DESC, email_messages.id DESC LIMIT 1000');
    $stmt->execute($params);
    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

function sendEmailDetailPayload(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        sendJson(['status' => 'error', 'message' => 'Передайте id письма'], 400);
    }
    $stmt = $pdo->prepare('SELECT * FROM email_messages WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $message = $stmt->fetch();
    if (!$message) {
        sendJson(['status' => 'error', 'message' => 'Письмо не найдено'], 404);
    }
    $attachments = $pdo->prepare('SELECT filename, mime_type, file_size FROM email_attachments WHERE message_id = :id ORDER BY id');
    $attachments->execute([':id' => $id]);
    $message['attachments'] = $attachments->fetchAll();
    sendJson(['status' => 'success', 'data' => $message]);
}

function saveEmailMailbox(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $data = readJsonBody();
    if ((string)($data['action'] ?? '') === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            sendJson(['status' => 'error', 'message' => 'Передайте id почтового ящика'], 400);
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM email_attachments WHERE message_id IN (SELECT id FROM email_messages WHERE mailbox_id=:id)')->execute([':id'=>$id]);
        $pdo->prepare('DELETE FROM email_messages WHERE mailbox_id=:id')->execute([':id'=>$id]);
        $stmt = $pdo->prepare('DELETE FROM email_mailboxes WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $pdo->commit();
        sendJson(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    }
    $id = (int)($data['id'] ?? 0);
    $manager = trim((string)($data['manager_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $host = normalizeImapHost((string)($data['imap_host'] ?? ''));
    $port = (int)($data['imap_port'] ?? 993);
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($manager === '' || $email === '' || $host === '' || $username === '') {
        sendJson(['status' => 'error', 'message' => 'Заполните ФИО, Email, IMAP Host и Логин'], 400);
    }
    if ($port < 1 || $port > 65535) {
        sendJson(['status' => 'error', 'message' => 'IMAP Port должен быть от 1 до 65535'], 400);
    }
    if ($password === '' && $id <= 0) {
        sendJson(['status' => 'error', 'message' => 'Для нового почтового ящика обязательно введите пароль'], 400);
    }
    $inboxFolder = trim((string)($data['inbox_folder'] ?? 'INBOX')) ?: 'INBOX';
    $sentFolder = trim((string)($data['sent_folder'] ?? 'Sent')) ?: 'Sent';
    $effectivePassword = resolveEmailMailboxPassword($pdo, $id, $password);
    if (!empty($data['enabled'])) {
        $test = testImapMailbox([
            'imap_host'=>$host, 'imap_port'=>$port, 'imap_ssl'=>!empty($data['imap_ssl']) ? 1 : 0,
            'username'=>$username, 'inbox_folder'=>$inboxFolder, 'sent_folder'=>$sentFolder,
        ], $effectivePassword);
        $inboxFolder = (string)$test['inbox_folder'];
        $sentFolder = (string)$test['sent_folder'];
    }
    $encryptedPassword = $password !== '' ? encryptSecret($password) : null;
    if ($id > 0) {
        $sql = 'UPDATE email_mailboxes SET manager_name=:manager_name,email=:email,imap_host=:imap_host,imap_port=:imap_port,imap_ssl=:imap_ssl,username=:username,inbox_folder=:inbox_folder,sent_folder=:sent_folder,enabled=:enabled,updated_at=NOW()';
        $params = [':manager_name'=>$manager, ':email'=>$email, ':imap_host'=>$host, ':imap_port'=>$port, ':imap_ssl'=>!empty($data['imap_ssl']) ? 1 : 0, ':username'=>$username, ':inbox_folder'=>$inboxFolder, ':sent_folder'=>$sentFolder, ':enabled'=>!empty($data['enabled']) ? 1 : 0, ':id'=>$id];
        if ($encryptedPassword !== null) {
            $sql .= ',password_encrypted=:password_encrypted';
            $params[':password_encrypted'] = $encryptedPassword;
        }
        $pdo->prepare($sql . ' WHERE id=:id')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO email_mailboxes (manager_name,email,imap_host,imap_port,imap_ssl,username,password_encrypted,inbox_folder,sent_folder,enabled) VALUES (:manager_name,:email,:imap_host,:imap_port,:imap_ssl,:username,:password_encrypted,:inbox_folder,:sent_folder,:enabled)')
            ->execute([':manager_name'=>$manager, ':email'=>$email, ':imap_host'=>$host, ':imap_port'=>$port, ':imap_ssl'=>!empty($data['imap_ssl']) ? 1 : 0, ':username'=>$username, ':password_encrypted'=>$encryptedPassword ?? '', ':inbox_folder'=>$inboxFolder, ':sent_folder'=>$sentFolder, ':enabled'=>!empty($data['enabled']) ? 1 : 0]);
    }
    sendJson(['status' => 'success']);
}

function resolveEmailMailboxPassword(PDO $pdo, int $id, string $password): string
{
    if ($password !== '') return $password;
    if ($id <= 0) return '';
    $stmt = $pdo->prepare('SELECT password_encrypted FROM email_mailboxes WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $encrypted = (string)($stmt->fetchColumn() ?: '');
    return $encrypted !== '' ? decryptSecret($encrypted) : '';
}

function testEmailMailboxSettings(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $data = readJsonBody();
    $id = (int)($data['id'] ?? 0);
    $password = resolveEmailMailboxPassword($pdo, $id, (string)($data['password'] ?? ''));
    $mailbox = [
        'imap_host' => normalizeImapHost((string)($data['imap_host'] ?? '')),
        'imap_port' => (int)($data['imap_port'] ?? 993),
        'imap_ssl' => !empty($data['imap_ssl']) ? 1 : 0,
        'username' => trim((string)($data['username'] ?? '')),
        'inbox_folder' => trim((string)($data['inbox_folder'] ?? 'INBOX')) ?: 'INBOX',
        'sent_folder' => trim((string)($data['sent_folder'] ?? 'Sent')) ?: 'Sent',
    ];
    if ($mailbox['imap_host'] === '' || $mailbox['username'] === '') {
        sendJson(['status'=>'error', 'message'=>'Заполните IMAP Host и Логин'], 400);
    }
    $result = testImapMailbox($mailbox, $password);
    sendJson(['status'=>'success', 'data'=>$result]);
}

try {
    $pdo = getPdo();
    $action = (string)($_GET['action'] ?? 'emails');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test') {
        testEmailMailboxSettings($pdo);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        saveEmailMailbox($pdo);
    }
    if ($action === 'settings') sendEmailSettingsPayload($pdo);
    if ($action === 'detail') sendEmailDetailPayload($pdo);
    if ($action === 'sync') sendJson(['status'=>'success', 'data'=>syncEmailMailboxes($pdo, isset($_GET['id']) ? (int)$_GET['id'] : null)]);
    sendEmailRegistryPayload($pdo);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
