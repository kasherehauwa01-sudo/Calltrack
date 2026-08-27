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

    $body = json_decode((string)file_get_contents('php://input'), true);
    $mode = is_array($body) && ($body['mode'] ?? '') === 'full' ? 'full' : 'delta';
    $result = startClientsCacheRefreshInBackground($mode);
    sendJson(['status'=>'success', 'message'=>'Обновление запущено', 'data'=>$result], 202);
} catch (Throwable $error) {
    $code = strpos($error->getMessage(), 'уже выполняется') !== false ? 409 : 502;
    sendJson(['status'=>'error', 'message'=>$error->getMessage(), 'data'=>clientsRefreshStatusPayload(readClientsRefreshStatus())], $code);
}
