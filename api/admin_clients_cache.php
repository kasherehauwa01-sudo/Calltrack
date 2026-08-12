<?php
declare(strict_types=1);

require_once __DIR__ . '/clients_cache_refresh.php';

function assertClientsCacheAdmin(): void
{
    $provided = (string)($_SERVER['HTTP_X_CALLTRACK_ADMIN_PASSWORD'] ?? '');
    if ($provided === '' || !hash_equals((string)CALLTRACK_ADMIN_PASSWORD, $provided)) {
        sendJson(['status'=>'error', 'message'=>'Требуется авторизация администратора'], 403);
    }
}

try {
    assertClientsCacheAdmin();
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        sendJson(['status'=>'success', 'data'=>clientsRefreshStatusPayload(readClientsRefreshStatus())]);
    }
    if ($method !== 'POST') sendJson(['status'=>'error', 'message'=>'Разрешены только GET и POST'], 405);

    @set_time_limit(max(30, (int)CLIENTS_API_TIMEOUT + 30));
    $result = runClientsCacheRefresh('manual');
    sendJson(['status'=>'success', 'message'=>'Кэш успешно обновлен', 'data'=>$result]);
} catch (Throwable $error) {
    $code = strpos($error->getMessage(), 'уже выполняется') !== false ? 409 : 502;
    sendJson(['status'=>'error', 'message'=>$error->getMessage(), 'data'=>clientsRefreshStatusPayload(readClientsRefreshStatus())], $code);
}
