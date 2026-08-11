<?php
declare(strict_types=1);

function decodeImapText(string $value): string
{
    if (function_exists('imap_mime_header_decode')) {
        $result = '';
        foreach (imap_mime_header_decode($value) as $part) {
            $charset = strtoupper((string)$part->charset);
            $text = (string)$part->text;
            $result .= ($charset !== 'DEFAULT' && $charset !== 'UTF-8')
                ? (iconv($charset, 'UTF-8//IGNORE', $text) ?: $text)
                : $text;
        }
        return $result;
    }
    return $value;
}

function imapAddress(object $header, string $field): string
{
    $addresses = $header->{$field} ?? [];
    if (!is_array($addresses) || !$addresses) return '';
    $address = $addresses[0];
    return isset($address->mailbox, $address->host) ? strtolower($address->mailbox . '@' . $address->host) : '';
}

function imapAddresses(object $header, string $field): string
{
    $result = [];
    foreach (($header->{$field} ?? []) as $address) {
        if (isset($address->mailbox, $address->host)) $result[] = strtolower($address->mailbox . '@' . $address->host);
    }
    return implode(', ', $result);
}

function decodeImapBody(string $body, int $encoding): string
{
    if ($encoding === 3) return base64_decode($body, true) ?: '';
    if ($encoding === 4) return quoted_printable_decode($body);
    return $body;
}

function collectImapParts($imap, int $messageNumber, object $part, string $section, array &$content, array &$attachments): void
{
    if (!empty($part->parts)) {
        foreach ($part->parts as $index => $child) {
            collectImapParts($imap, $messageNumber, $child, $section === '' ? (string)($index + 1) : $section . '.' . ($index + 1), $content, $attachments);
        }
        return;
    }
    $body = $section === '' ? imap_body($imap, $messageNumber, FT_PEEK) : imap_fetchbody($imap, $messageNumber, $section, FT_PEEK);
    $body = decodeImapBody((string)$body, (int)($part->encoding ?? 0));
    $params = array_merge($part->parameters ?? [], $part->dparameters ?? []);
    $filename = '';
    foreach ($params as $param) {
        if (in_array(strtolower((string)$param->attribute), ['filename', 'name'], true)) $filename = decodeImapText((string)$param->value);
    }
    if ($filename !== '') {
        $attachments[] = ['filename'=>$filename, 'mime_type'=>strtolower((string)($part->subtype ?? 'application/octet-stream')), 'file_size'=>strlen($body)];
        return;
    }
    if ((int)($part->type ?? 0) === 0) {
        $subtype = strtoupper((string)($part->subtype ?? 'PLAIN'));
        $content[$subtype === 'HTML' ? 'html' : 'text'] .= $body;
    }
}

function importImapFolder(PDO $pdo, array $mailbox, string $folder, string $direction): int
{
    $flags = !empty($mailbox['imap_ssl']) ? '/imap/ssl' : '/imap/notls';
    $server = sprintf('{%s:%d%s}%s', $mailbox['imap_host'], (int)$mailbox['imap_port'], $flags, $folder);
    $imap = @imap_open($server, $mailbox['username'], decryptSecret($mailbox['password_encrypted']), OP_READONLY, 1);
    if ($imap === false) throw new RuntimeException('Не удалось подключиться к папке ' . $folder . ': ' . (imap_last_error() ?: 'ошибка IMAP'));
    $imported = 0;
    try {
        $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
        foreach ($uids as $uid) {
            $exists = $pdo->prepare('SELECT 1 FROM email_messages WHERE mailbox_id=:mailbox_id AND imap_folder=:folder AND imap_uid=:uid');
            $exists->execute([':mailbox_id'=>$mailbox['id'], ':folder'=>$folder, ':uid'=>$uid]);
            if ($exists->fetchColumn()) continue;
            $number = imap_msgno($imap, (int)$uid);
            $header = imap_headerinfo($imap, $number);
            $structure = imap_fetchstructure($imap, $number);
            $content = ['text'=>'', 'html'=>'']; $attachments = [];
            collectImapParts($imap, $number, $structure, '', $content, $attachments);
            $clientEmail = $direction === 'incoming' ? imapAddress($header, 'from') : imapAddress($header, 'to');
            $date = date('Y-m-d H:i:s', isset($header->udate) ? (int)$header->udate : time());
            $stmt = $pdo->prepare('INSERT INTO email_messages (mailbox_id,manager_name,direction,sent_at,from_email,from_name,to_emails,cc_emails,client_email,subject,body_text,body_html,message_size,has_attachments,attachment_count,imap_uid,imap_folder,message_id,incoming_status,outgoing_status) VALUES (:mailbox_id,:manager_name,:direction,:sent_at,:from_email,:from_name,:to_emails,:cc_emails,:client_email,:subject,:body_text,:body_html,:message_size,:has_attachments,:attachment_count,:imap_uid,:imap_folder,:message_id,:incoming_status,:outgoing_status)');
            $stmt->execute([':mailbox_id'=>$mailbox['id'], ':manager_name'=>$mailbox['manager_name'], ':direction'=>$direction, ':sent_at'=>$date, ':from_email'=>imapAddress($header, 'from'), ':from_name'=>decodeImapText((string)($header->fromaddress ?? '')), ':to_emails'=>imapAddresses($header, 'to'), ':cc_emails'=>imapAddresses($header, 'cc'), ':client_email'=>$clientEmail, ':subject'=>decodeImapText((string)($header->subject ?? '')), ':body_text'=>$content['text'], ':body_html'=>$content['html'], ':message_size'=>(int)($header->Size ?? 0), ':has_attachments'=>$attachments ? 1 : 0, ':attachment_count'=>count($attachments), ':imap_uid'=>$uid, ':imap_folder'=>$folder, ':message_id'=>(string)($header->message_id ?? ''), ':incoming_status'=>$direction === 'incoming' && empty($header->Unseen) ? 'read' : 'unread', ':outgoing_status'=>$direction === 'outgoing' ? 'delivered' : null]);
            $messageId = (int)$pdo->lastInsertId();
            foreach ($attachments as $attachment) {
                $pdo->prepare('INSERT INTO email_attachments (message_id,filename,mime_type,file_size) VALUES (:message_id,:filename,:mime_type,:file_size)')->execute([':message_id'=>$messageId] + $attachment);
            }
            $imported++;
        }
    } finally { imap_close($imap); }
    return $imported;
}

function syncEmailMailboxes(PDO $pdo, ?int $mailboxId = null): array
{
    ensureEmailTables($pdo);
    if (!function_exists('imap_open')) throw new RuntimeException('На сервере не установлено PHP-расширение IMAP');
    $sql = 'SELECT * FROM email_mailboxes WHERE enabled=1' . ($mailboxId ? ' AND id=:id' : '');
    $stmt = $pdo->prepare($sql); $stmt->execute($mailboxId ? [':id'=>$mailboxId] : []);
    $result = ['imported'=>0, 'mailboxes'=>0, 'errors'=>[]];
    foreach ($stmt->fetchAll() as $mailbox) {
        try {
            $count = importImapFolder($pdo, $mailbox, $mailbox['inbox_folder'], 'incoming');
            if ($mailbox['sent_folder'] !== $mailbox['inbox_folder']) $count += importImapFolder($pdo, $mailbox, $mailbox['sent_folder'], 'outgoing');
            $pdo->prepare("UPDATE email_mailboxes SET last_sync_at=NOW(),sync_status='success',sync_error=NULL WHERE id=:id")->execute([':id'=>$mailbox['id']]);
            $result['imported'] += $count; $result['mailboxes']++;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE email_mailboxes SET last_sync_at=NOW(),sync_status='error',sync_error=:error WHERE id=:id")->execute([':id'=>$mailbox['id'], ':error'=>$e->getMessage()]);
            $result['errors'][] = ['id'=>$mailbox['id'], 'message'=>$e->getMessage()];
        }
    }
    return $result;
}
