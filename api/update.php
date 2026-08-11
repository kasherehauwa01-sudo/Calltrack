<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function isDownloadRequest(): bool
{
    $download = (string)($_GET['download'] ?? $_GET['apk'] ?? '');
    if ($download === '1' || strcasecmp($download, 'true') === 0) {
        return true;
    }

    // На некоторых конфигурациях хостинга параметры могут не попасть в $_GET.
    // Поэтому дополнительно проверяем исходную строку запроса и REQUEST_URI.
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    parse_str(parse_url($uri, PHP_URL_QUERY) ?: $query, $params);
    $fallback = (string)($params['download'] ?? $params['apk'] ?? '');
    return $fallback === '1' || strcasecmp($fallback, 'true') === 0;
}

function updateJsonPath(): string
{
    return dirname(__DIR__) . '/updates/update.json';
}

function loadUpdatePayload(): array
{
    $path = updateJsonPath();
    if (!is_file($path)) {
        sendJson(['status' => 'error', 'message' => 'update.json not found'], 404);
    }

    $content = file_get_contents($path);
    $data = json_decode($content === false ? '' : $content, true);
    if (!is_array($data)) {
        sendJson(['status' => 'error', 'message' => 'update.json is invalid'], 500);
    }
    return $data;
}

function downloadUrl(array $data): string
{
    $versionCode = (int)($data['versionCode'] ?? 0);
    return updateDownloadUrlForVersion($versionCode);
}

if (isDownloadRequest()) {
    $versionCode = (int)($_GET['versionCode'] ?? $_GET['version_code'] ?? 0);
    $apk = resolveUpdateApk(getPdo(), $versionCode);
    streamApkFile($apk['path'], $apk['filename']);
}

$data = loadUpdatePayload();
$data['status'] = 'ok';
$data['apk'] = downloadUrl($data);
sendJson($data);
