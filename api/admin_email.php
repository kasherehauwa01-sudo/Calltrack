<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function sendEmailSettingsPayload(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $stmt = $pdo->query('SELECT id, manager_name, email, imap_host, imap_port, imap_ssl, username, inbox_folder, sent_folder, enabled, last_sync_at, created_at, updated_at FROM email_mailboxes ORDER BY manager_name, email');
    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

function sendEmailRegistryPayload(PDO $pdo): void
{
    ensureEmailTables($pdo);
    $where = [];
    $params = [];
    foreach (['manager' => 'manager_name', 'direction' => 'direction', 'client_status' => 'client_status'] as $param => $column) {
        $value = trim((string)($_GET[$param] ?? ''));
        if ($value !== '') {
            $where[] = "$column = :$param";
            $params[":$param"] = $value;
        }
    }
    if (!empty($_GET['has_attachments'])) {
        $where[] = 'has_attachments = :has_attachments';
        $params[':has_attachments'] = (int)$_GET['has_attachments'] === 1 ? 1 : 0;
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'sent_at >= :date_from';
        $params[':date_from'] = normalizeDate((string)$_GET['date_from']) . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'sent_at <= :date_to';
        $params[':date_to'] = normalizeDate((string)$_GET['date_to']) . ' 23:59:59';
    }
    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(subject LIKE :search OR client_email LIKE :search OR client_name LIKE :search OR body_text LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    $sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $pdo->prepare('SELECT id, sent_at, manager_name, direction, client_name, client_email, subject, client_status, message_size, has_attachments, attachment_count, imap_uid FROM email_messages' . $sqlWhere . ' ORDER BY sent_at DESC, id DESC LIMIT 1000');
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
        $stmt = $pdo->prepare('DELETE FROM email_mailboxes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendJson(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    }
    $id = (int)($data['id'] ?? 0);
    $manager = trim((string)($data['manager_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $host = trim((string)($data['imap_host'] ?? ''));
    $port = (int)($data['imap_port'] ?? 993);
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($manager === '' || $email === '' || $host === '' || $username === '') {
        sendJson(['status' => 'error', 'message' => 'Заполните ФИО, Email, IMAP Host и Логин'], 400);
    }
    $encryptedPassword = $password !== '' ? encryptSecret($password) : null;
    if ($id > 0) {
        $sql = 'UPDATE email_mailboxes SET manager_name=:manager_name,email=:email,imap_host=:imap_host,imap_port=:imap_port,imap_ssl=:imap_ssl,username=:username,inbox_folder=:inbox_folder,sent_folder=:sent_folder,enabled=:enabled,updated_at=NOW()';
        $params = [':manager_name'=>$manager, ':email'=>$email, ':imap_host'=>$host, ':imap_port'=>$port, ':imap_ssl'=>!empty($data['imap_ssl']) ? 1 : 0, ':username'=>$username, ':inbox_folder'=>trim((string)($data['inbox_folder'] ?? 'INBOX')) ?: 'INBOX', ':sent_folder'=>trim((string)($data['sent_folder'] ?? 'Sent')) ?: 'Sent', ':enabled'=>!empty($data['enabled']) ? 1 : 0, ':id'=>$id];
        if ($encryptedPassword !== null) {
            $sql .= ',password_encrypted=:password_encrypted';
            $params[':password_encrypted'] = $encryptedPassword;
        }
        $pdo->prepare($sql . ' WHERE id=:id')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO email_mailboxes (manager_name,email,imap_host,imap_port,imap_ssl,username,password_encrypted,inbox_folder,sent_folder,enabled) VALUES (:manager_name,:email,:imap_host,:imap_port,:imap_ssl,:username,:password_encrypted,:inbox_folder,:sent_folder,:enabled)')
            ->execute([':manager_name'=>$manager, ':email'=>$email, ':imap_host'=>$host, ':imap_port'=>$port, ':imap_ssl'=>!empty($data['imap_ssl']) ? 1 : 0, ':username'=>$username, ':password_encrypted'=>$encryptedPassword ?? '', ':inbox_folder'=>trim((string)($data['inbox_folder'] ?? 'INBOX')) ?: 'INBOX', ':sent_folder'=>trim((string)($data['sent_folder'] ?? 'Sent')) ?: 'Sent', ':enabled'=>!empty($data['enabled']) ? 1 : 0]);
    }
    sendJson(['status' => 'success']);
}

try {
    $pdo = getPdo();
    $action = (string)($_GET['action'] ?? 'emails');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        saveEmailMailbox($pdo);
    }
    if ($action === 'settings') sendEmailSettingsPayload($pdo);
    if ($action === 'detail') sendEmailDetailPayload($pdo);
    sendEmailRegistryPayload($pdo);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
