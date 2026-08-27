<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$storage = sys_get_temp_dir() . '/calltrack-client-calls-' . bin2hex(random_bytes(4));
putenv('CALLTRACK_STORAGE_DIR=' . $storage);
putenv('CALLTRACK_CLIENTS_DISABLE_SQLITE=1');
require_once $root . '/api/client_directory.php';

$phone = '9991234567';
$shardFile = clientsLookupShardFile($phone);
file_put_contents($shardFile, json_encode([
    $phone => [['phone'=>'+7'.$phone, 'name'=>'ООО Ромашка', 'fields'=>['status'=>'active', 'emails'=>['info@example.ru']]]],
], JSON_UNESCAPED_UNICODE));
file_put_contents(clientsLookupReadyFile(), date(DATE_ATOM));
file_put_contents(clientsCacheFile(), json_encode([[
    'name'=>'ООО Ромашка',
    'phones'=>[$phone],
    'fields'=>['Email'=>'info@example.ru'],
]], JSON_UNESCAPED_UNICODE));

$result = lookupClientNamesByPhones(['+7 (999) 123-45-67', '+7 (999) 123-45-67']);
if (($result[$phone] ?? []) !== ['ООО Ромашка']) {
    throw new RuntimeException('Наименование клиента не найдено в локальном shard-кэше');
}
$details = lookupClientDetailsByPhones([$phone]);
if (($details[$phone][0]['emails'] ?? []) !== ['info@example.ru']) {
    throw new RuntimeException('Email клиента не найден в локальной карточке Clients');
}
$emailDetails = lookupClientDetailsByEmails(['INFO@example.ru', 'unknown@example.ru']);
if (($emailDetails['info@example.ru'][0]['name'] ?? '') !== 'ООО Ромашка') {
    throw new RuntimeException('Наименование клиента не найдено в локальном кэше по email получателя');
}
if (($emailDetails['unknown@example.ru'] ?? null) !== []) {
    throw new RuntimeException('Для неизвестного email должен возвращаться пустой результат');
}

$html = (string)file_get_contents($root . '/analizmop/index.html');
$api = (string)file_get_contents($root . '/analizmop/api.js');
foreach (['api.js?v=20260827-client-email-timeline', 'refreshClientEmailTimeline', 'if(isClientCalls) refreshClientEmailTimeline()', 'if(runSync&&!syncError) refreshClientEmailTimeline()', 'clientEmailsLoading', 'loadClientNamesForCalls', 'loadClientNamesForCalls([])', 'getCachedClientName', 'clientNamesByEmail', 'result.emailClients', 'normalizeClientLookupEmail', 'group.displayName', "formatPhoneForDisplay(call?.phone)||existing||'Без номера телефона'", 'fetchClientOutgoingEmails', 'ADMIN_EMAIL_API_URL}?direction=outgoing', "cache:'no-store'", 'Email отправлен', 'Отправлено email:', 'data-email-id', 'background:#fef3c7', 'border:1px solid #f59e0b'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException("Вкладка звонков не использует Clients: {$required}");
}
if (!str_contains($api, 'lookupClientNames') || !str_contains($api, 'JSON.stringify({ phones, emails })')) {
    throw new RuntimeException('Frontend не вызывает пакетный поиск Clients');
}

function removeTestDirectory(string $path): void {
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $child = $path . '/' . $item;
        is_dir($child) ? removeTestDirectory($child) : @unlink($child);
    }
    @rmdir($path);
}
removeTestDirectory($storage);

echo "client_calls_names_test: OK\n";
