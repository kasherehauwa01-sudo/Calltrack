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

function normalizeImapContentText(string $value, string $declaredCharset = ''): string
{
    if ($value === '') return '';
    $charset = strtoupper(trim($declaredCharset, " \t\n\r\0\x0B\"'"));
    if ($charset !== '' && !in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII', 'DEFAULT'], true)) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
        if ($converted !== false) return $converted;
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) return $value;
    if (preg_match('//u', $value) === 1) return $value;
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $detected = mb_detect_encoding($value, ['Windows-1251', 'KOI8-R', 'ISO-8859-1'], true);
        if ($detected !== false) return mb_convert_encoding($value, 'UTF-8', $detected);
    }
    // Старые русскоязычные письма часто не содержат charset, хотя тело записано в CP1251.
    $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $value);
    return $converted !== false ? $converted : (@iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '');
}

function executeEmailMessageInsert(PDOStatement $statement, array $messageData): bool
{
    try {
        $statement->execute($messageData);
        return true;
    } catch (PDOException $error) {
        // На старых таблицах/серверах MySQL отдельное MIME-тело может всё равно
        // содержать неподдерживаемую последовательность. Не теряем из-за этого
        // само письмо: повторно сохраняем заголовки и адреса без проблемного тела.
        if (!str_contains($error->getMessage(), 'Incorrect string value')) throw $error;
        $messageData[':body_text'] = '';
        $messageData[':body_html'] = '';
        $statement->execute($messageData);
        return false;
    }
}

function normalizeImapHost(string $host): string
{
    $host = trim($host);
    $host = preg_replace('~^(?:imap|imaps|ssl|tls)://~i', '', $host) ?? $host;
    $host = trim($host, " \t\n\r\0\x0B{}/");
    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }
    return $host;
}

function imapConnectionFlags(array $mailbox): string
{
    $transport = empty($mailbox['imap_ssl'])
        ? '/imap/notls'
        : ((int)($mailbox['imap_port'] ?? 993) === 993 ? '/imap/ssl' : '/imap/tls');
    // /readonly и OP_READONLY ниже независимо запрещают менять флаги писем.
    return $transport . '/readonly';
}

function imapServerPrefix(array $mailbox): string
{
    return sprintf('{%s:%d%s}', normalizeImapHost((string)$mailbox['imap_host']), (int)$mailbox['imap_port'], imapConnectionFlags($mailbox));
}

function decodeImapFolderName(string $folder): string
{
    if (function_exists('imap_mutf7_to_utf8')) {
        $decoded = @imap_mutf7_to_utf8($folder);
        if (is_string($decoded) && $decoded !== '') return $decoded;
    }
    if (function_exists('imap_utf7_decode')) {
        $decoded = @imap_utf7_decode($folder);
        if (is_string($decoded) && $decoded !== '') return $decoded;
    }
    // Некоторые сборки PHP не содержат IMAP UTF-7 функций. Mail.ru при этом
    // возвращает русские папки как modified UTF-7 (например &BB4E...-).
    return preg_replace_callback('/&([^-]*)-/', static function (array $match): string {
        if ($match[1] === '') return '&';
        $base64 = str_replace(',', '/', $match[1]);
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $utf16 = base64_decode($base64, true);
        return $utf16 === false ? $match[0] : (@iconv('UTF-16BE', 'UTF-8//IGNORE', $utf16) ?: $match[0]);
    }, $folder) ?? $folder;
}

function encodeImapFolderName(string $folder): string
{
    if (function_exists('imap_utf8_to_mutf7')) {
        $encoded = @imap_utf8_to_mutf7($folder);
        if (is_string($encoded) && $encoded !== '') return $encoded;
    }
    if (function_exists('imap_utf7_encode')) {
        $encoded = @imap_utf7_encode($folder);
        if (is_string($encoded) && $encoded !== '') return $encoded;
    }
    $parts = preg_split('/([^\x20-\x7e]+)/u', $folder, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$folder];
    return implode('', array_map(static function (string $part): string {
        if ($part === '&') return '&-';
        if (preg_match('/^[\x20-\x7e]*$/', $part)) return str_replace('&', '&-', $part);
        $utf16 = @iconv('UTF-8', 'UTF-16BE//IGNORE', $part);
        return $utf16 === false ? $part : '&' . rtrim(str_replace('/', ',', base64_encode($utf16)), '=') . '-';
    }, $parts));
}

function isOutgoingImapFolder(string $folder): bool
{
    $parts = preg_split('~[\\/.]+~u', trim($folder)) ?: [$folder];
    $name = trim((string)end($parts));
    return preg_match('/^(?:sent(?: items| messages| mail| objects)?|отправ[^\/]*|исходящ[^\/]*)$/iu', $name) === 1;
}

function isOutgoingImapFolder(string $folder): bool
{
    $parts = preg_split('~[\\/.]+~u', trim($folder)) ?: [$folder];
    $name = trim((string)end($parts));
    return preg_match('/^(?:sent(?: items| messages| mail| objects)?|отправ[^\/]*|исходящ[^\/]*)$/iu', $name) === 1;
}

function listImapFolders($imap, string $prefix): array
{
    $folders = imap_list($imap, $prefix, '*') ?: [];
    return array_values(array_map(static function (string $folder) use ($prefix): string {
        $name = str_starts_with($folder, $prefix) ? substr($folder, strlen($prefix)) : $folder;
        return decodeImapFolderName($name);
    }, $folders));
}

function findImapFolder(array $folders, string $requested, string $direction): string
{
    $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    foreach ($folders as $folder) {
        if ($lower($folder) === $lower($requested)) return $folder;
    }
    if ($direction === 'outgoing') {
        foreach ($folders as $folder) {
            if (isOutgoingImapFolder($folder)) return $folder;
        }
    }
    return $requested;
}

function sentImapFolderCandidates(array $folders, string $requested): array
{
    $candidates = [];
    foreach ($folders as $folder) {
        if (strcasecmp($folder, $requested) === 0 || isOutgoingImapFolder($folder)) {
            $candidates[$folder] = $folder;
        }
    }
    return array_values($candidates);
}

function newestImapFolder(array $mailbox, string $password, array $folders, string $fallback): string
{
    $candidates = sentImapFolderCandidates($folders, $fallback);
    if (!$candidates) return $fallback;
    if (count($candidates) === 1) return $candidates[0];
    $bestFolder = $fallback;
    $bestTimestamp = -1;
    foreach ($candidates as $folder) {
        $imap = @imap_open(imapServerPrefix($mailbox) . encodeImapFolderName($folder), (string)$mailbox['username'], $password, OP_READONLY, 1);
        if ($imap === false) continue;
        try {
            $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
            $latestUid = $uids ? max(array_map('intval', $uids)) : 0;
            $overview = $latestUid > 0 ? imap_fetch_overview($imap, (string)$latestUid, FT_UID) : [];
            $timestamp = (int)($overview[0]->udate ?? 0);
            if ($timestamp > $bestTimestamp) {
                $bestTimestamp = $timestamp;
                $bestFolder = $folder;
            }
        } finally {
            imap_close($imap);
        }
    }
    return $bestFolder;
}

function discoverSentImapFolder(array $mailbox): string
{
    $password = decryptSecret((string)$mailbox['password_encrypted']);
    $prefix = imapServerPrefix($mailbox);
    $inbox = trim((string)($mailbox['inbox_folder'] ?? 'INBOX')) ?: 'INBOX';
    $imap = @imap_open($prefix . encodeImapFolderName($inbox), (string)$mailbox['username'], $password, OP_READONLY, 1);
    if ($imap === false) throw new RuntimeException('Не удалось получить список IMAP-папок: ' . (imap_last_error() ?: 'ошибка IMAP'));
    try {
        $folders = listImapFolders($imap, $prefix);
    } finally {
        imap_close($imap);
    }
    $configured = trim((string)($mailbox['sent_folder'] ?? 'Sent')) ?: 'Sent';
    if (str_contains(strtolower((string)$mailbox['imap_host']), 'mail.ru')) {
        // Пробуем оба имени напрямую. Это покрывает серверы Mail.ru, которые
        // скрывают системную папку из imap_list или возвращают её только в UTF-7.
        $folders = array_values(array_unique([...$folders, 'Sent', 'Отправленные']));
    }
    $fallback = findImapFolder($folders, $configured, 'outgoing');
    return newestImapFolder($mailbox, $password, $folders, $fallback);
}

function testImapMailbox(array $mailbox, string $password): array
{
    if (!function_exists('imap_open')) throw new RuntimeException('На сервере не установлено PHP-расширение IMAP');
    if ($password === '') throw new RuntimeException('Введите пароль почтового ящика или пароль приложения');
    $prefix = imapServerPrefix($mailbox);
    imap_timeout(IMAP_OPENTIMEOUT, 15);
    $imap = @imap_open($prefix . 'INBOX', (string)$mailbox['username'], $password, OP_READONLY, 1);
    if ($imap === false) {
        $detail = imap_last_error() ?: 'сервер не сообщил причину';
        throw new RuntimeException('IMAP-подключение не установлено: ' . $detail . '. Проверьте логин, пароль приложения, порт и SSL/TLS');
    }
    try {
        $folders = listImapFolders($imap, $prefix);
        $sentFallback = findImapFolder($folders, (string)($mailbox['sent_folder'] ?? 'Sent'), 'outgoing');
        return [
            'folders' => $folders,
            'inbox_folder' => findImapFolder($folders, (string)($mailbox['inbox_folder'] ?? 'INBOX'), 'incoming'),
            'sent_folder' => newestImapFolder($mailbox, $password, $folders, $sentFallback),
        ];
    } finally {
        imap_close($imap);
    }
}

function fetchImapBodyWithoutMarkingRead($imap, int $messageNumber, string $section): string
{
    // FT_PEEK загружает содержимое, не устанавливая серверный флаг \Seen.
    return (string)($section === ''
        ? imap_body($imap, $messageNumber, FT_PEEK)
        : imap_fetchbody($imap, $messageNumber, $section, FT_PEEK));
}

function isImapMessageSeen($imap, int $uid): bool
{
    // Обзор по UID не открывает тело письма и не меняет его состояние.
    $overview = imap_fetch_overview($imap, (string)$uid, FT_UID);
    return !empty($overview[0]->seen);
}

function normalizeImapParameters($value): array
{
    if ($value === null) return [];
    if (is_array($value)) return array_values($value);
    if ($value instanceof Traversable) return array_values(iterator_to_array($value));
    if (is_object($value)) {
        // Некоторые версии PHP IMAP возвращают один параметр как stdClass,
        // а не как массив из одного элемента.
        if (isset($value->attribute) || isset($value->value)) return [$value];
        return array_values((array)$value);
    }
    return [];
}

function collectImapParts($imap, int $messageNumber, object $part, string $section, array &$content, array &$attachments, bool $fetchTextBodies = true): void
{
    if (!empty($part->parts)) {
        foreach ($part->parts as $index => $child) {
            collectImapParts($imap, $messageNumber, $child, $section === '' ? (string)($index + 1) : $section . '.' . ($index + 1), $content, $attachments, $fetchTextBodies);
        }
        return;
    }
    $params = array_merge(
        normalizeImapParameters($part->parameters ?? null),
        normalizeImapParameters($part->dparameters ?? null)
    );
    $filename = '';
    $charset = '';
    foreach ($params as $param) {
        if (!is_object($param)) continue;
        $attribute = strtolower((string)($param->attribute ?? ''));
        if (in_array($attribute, ['filename', 'name'], true)) $filename = decodeImapText((string)($param->value ?? ''));
        if ($attribute === 'charset') $charset = (string)($param->value ?? '');
    }
    if ($filename !== '') {
        // Для реестра нужны только метаданные вложения. Загрузка бинарного тела
        // больших файлов замедляла HTTP-запрос и приводила к ответу 504.
        $attachments[] = ['filename'=>$filename, 'mime_type'=>strtolower((string)($part->subtype ?? 'application/octet-stream')), 'file_size'=>(int)($part->bytes ?? 0)];
        return;
    }
    if ($fetchTextBodies && (int)($part->type ?? 0) === 0) {
        $body = fetchImapBodyWithoutMarkingRead($imap, $messageNumber, $section);
        $body = decodeImapBody($body, (int)($part->encoding ?? 0));
        $subtype = strtoupper((string)($part->subtype ?? 'PLAIN'));
        $content[$subtype === 'HTML' ? 'html' : 'text'] .= normalizeImapContentText($body, $charset);
    }
}

function importImapFolder(PDO $pdo, array $mailbox, string $folder, string $direction, array &$messageErrors = [], int $limit = 50): int
{
    imap_timeout(IMAP_OPENTIMEOUT, 10);
    imap_timeout(IMAP_READTIMEOUT, 10);
    $server = imapServerPrefix($mailbox) . encodeImapFolderName($folder);
    $imap = @imap_open($server, $mailbox['username'], decryptSecret($mailbox['password_encrypted']), OP_READONLY, 1);
    if ($imap === false) throw new RuntimeException('Не удалось подключиться к папке ' . $folder . ': ' . (imap_last_error() ?: 'ошибка IMAP'));
    $imported = 0;
    try {
        $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
        // Новые UID обрабатываем первыми: пользователь сразу увидит свежие письма,
        // даже если в большом архиве синхронизация займёт несколько запусков.
        rsort($uids, SORT_NUMERIC);
        $existingStatement = $pdo->prepare('SELECT imap_uid FROM email_messages WHERE mailbox_id=:mailbox_id AND imap_folder=:folder');
        $existingStatement->execute([':mailbox_id'=>$mailbox['id'], ':folder'=>$folder]);
        $existingUids = array_fill_keys(array_map('intval', $existingStatement->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($uids as $uid) {
            if ($limit > 0 && $imported >= $limit) break;
            $uid = (int)$uid;
            if (isset($existingUids[$uid])) continue;
            try {
                $number = imap_msgno($imap, (int)$uid);
                if ($number < 1) throw new RuntimeException('IMAP не вернул номер сообщения');
                $wasSeen = isImapMessageSeen($imap, (int)$uid);
                $header = imap_headerinfo($imap, $number);
                $structure = imap_fetchstructure($imap, $number);
                if (!is_object($header) || !is_object($structure)) throw new RuntimeException('IMAP не вернул заголовок или структуру письма');
                $content = ['text'=>'', 'html'=>'']; $attachments = [];
                // Реестр и временная шкала используют заголовки, адреса и тему.
                // Тела писем не скачиваем в HTTP-запросе синхронизации: именно
                // медленная загрузка MIME-тел приводила к тайм-ауту прокси 504.
                collectImapParts($imap, $number, $structure, '', $content, $attachments, false);
                $clientEmail = $direction === 'incoming' ? imapAddress($header, 'from') : imapAddress($header, 'to');
                $date = date('Y-m-d H:i:s', isset($header->udate) ? (int)$header->udate : time());
                $stmt = $pdo->prepare('INSERT INTO email_messages (mailbox_id,manager_name,direction,sent_at,from_email,from_name,to_emails,cc_emails,client_email,subject,body_text,body_html,message_size,has_attachments,attachment_count,imap_uid,imap_folder,message_id,incoming_status,outgoing_status) VALUES (:mailbox_id,:manager_name,:direction,:sent_at,:from_email,:from_name,:to_emails,:cc_emails,:client_email,:subject,:body_text,:body_html,:message_size,:has_attachments,:attachment_count,:imap_uid,:imap_folder,:message_id,:incoming_status,:outgoing_status)');
                $messageData = [':mailbox_id'=>$mailbox['id'], ':manager_name'=>$mailbox['manager_name'], ':direction'=>$direction, ':sent_at'=>$date, ':from_email'=>imapAddress($header, 'from'), ':from_name'=>decodeImapText((string)($header->fromaddress ?? '')), ':to_emails'=>imapAddresses($header, 'to'), ':cc_emails'=>imapAddresses($header, 'cc'), ':client_email'=>$clientEmail, ':subject'=>decodeImapText((string)($header->subject ?? '')), ':body_text'=>$content['text'], ':body_html'=>$content['html'], ':message_size'=>(int)($header->Size ?? 0), ':has_attachments'=>$attachments ? 1 : 0, ':attachment_count'=>count($attachments), ':imap_uid'=>$uid, ':imap_folder'=>$folder, ':message_id'=>(string)($header->message_id ?? ''), ':incoming_status'=>$direction === 'incoming' && !$wasSeen ? 'unread' : 'read', ':outgoing_status'=>$direction === 'outgoing' ? 'delivered' : null];
                foreach ([':manager_name', ':from_email', ':from_name', ':to_emails', ':cc_emails', ':client_email', ':subject', ':body_text', ':body_html', ':imap_folder', ':message_id'] as $textKey) {
                    $messageData[$textKey] = normalizeImapContentText((string)$messageData[$textKey]);
                }
                executeEmailMessageInsert($stmt, $messageData);
                $messageId = (int)$pdo->lastInsertId();
                foreach ($attachments as $attachment) {
                    $attachment['filename'] = normalizeImapContentText((string)$attachment['filename']);
                    $pdo->prepare('INSERT INTO email_attachments (message_id,filename,mime_type,file_size) VALUES (:message_id,:filename,:mime_type,:file_size)')->execute([':message_id'=>$messageId] + $attachment);
                }
                $imported++;
            } catch (Throwable $e) {
                // Одно повреждённое письмо не должно блокировать все более новые UID.
                $messageErrors[] = ['folder'=>$folder, 'uid'=>(int)$uid, 'message'=>$e->getMessage()];
            }
        }
    } finally { imap_close($imap); }
    return $imported;
}

function syncEmailMailboxes(PDO $pdo, ?int $mailboxId = null, int $limitPerMailbox = 50): array
{
    ensureEmailTables($pdo);
    if (!function_exists('imap_open')) throw new RuntimeException('На сервере не установлено PHP-расширение IMAP');
    $sql = 'SELECT * FROM email_mailboxes WHERE enabled=1' . ($mailboxId ? ' AND id=:id' : '');
    $stmt = $pdo->prepare($sql); $stmt->execute($mailboxId ? [':id'=>$mailboxId] : []);
    $result = ['imported'=>0, 'mailboxes'=>0, 'errors'=>[]];
    foreach ($stmt->fetchAll() as $mailbox) {
        try {
            // Для обычных серверов используем сохранённую папку, а для Mail.ru и
            // универсального значения Sent дополнительно сверяем список папок.
            $mailbox['sent_folder'] = trim((string)($mailbox['sent_folder'] ?? '')) ?: 'Sent';
            $messageErrors = [];
            // Для Mail.ru, включая почту на собственном домене, фактическая папка
            // обычно называется «Отправленные». Определяем её автоматически, даже
            // если в старой настройке осталось универсальное значение Sent.
            if (str_contains(strtolower((string)$mailbox['imap_host']), 'mail.ru') || strcasecmp($mailbox['sent_folder'], 'Sent') === 0) {
                $mailbox['sent_folder'] = discoverSentImapFolder($mailbox);
            }
            // Реестр хранит только исходящие письма. Фоновый cron передаёт limit=0
            // и за одно подключение дочитывает папку Sent до самого старого письма.
            $count = importImapFolder($pdo, $mailbox, $mailbox['sent_folder'], 'outgoing', $messageErrors, $limitPerMailbox);
            $syncError = $messageErrors ? implode('; ', array_map(static fn(array $error): string => sprintf('%s UID %d: %s', $error['folder'], $error['uid'], $error['message']), array_slice($messageErrors, 0, 5))) : null;
            $syncStatus = $messageErrors ? 'error' : 'success';
            $pdo->prepare("UPDATE email_mailboxes SET sent_folder=:sent_folder,last_sync_at=NOW(),sync_status=:sync_status,sync_error=:sync_error WHERE id=:id")->execute([':sent_folder'=>$mailbox['sent_folder'], ':sync_status'=>$syncStatus, ':sync_error'=>$syncError, ':id'=>$mailbox['id']]);
            $result['imported'] += $count; $result['mailboxes']++;
            foreach ($messageErrors as $error) $result['errors'][] = ['id'=>$mailbox['id']] + $error;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE email_mailboxes SET last_sync_at=NOW(),sync_status='error',sync_error=:error WHERE id=:id")->execute([':id'=>$mailbox['id'], ':error'=>$e->getMessage()]);
            $result['errors'][] = ['id'=>$mailbox['id'], 'message'=>$e->getMessage()];
        }
    }
    return $result;
}
