<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

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

function apkPathFromPayload(array $data): string
{
    // Сначала используем имя файла, которое формирует админ-панель. Это важно,
    // потому что поле apk в update.json теперь может указывать на download-proxy.
    $filename = basename((string)($data['filename'] ?? ''));
    if ($filename === '') {
        // Поддержка старых update.json: раньше в apk лежала прямая ссылка /updates/*.apk.
        $apkUrl = (string)($data['apk'] ?? '');
        $path = (string)(parse_url($apkUrl, PHP_URL_PATH) ?: '');
        $filename = basename(rawurldecode($path));
    }
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'apk') {
        sendJson(['status' => 'error', 'message' => 'APK filename is invalid'], 500);
    }

    $updatesDir = realpath(dirname(__DIR__) . '/updates');
    $apkPath = realpath(dirname(__DIR__) . '/updates/' . $filename);
    if ($updatesDir === false || $apkPath === false || strpos($apkPath, $updatesDir) !== 0 || !is_file($apkPath)) {
        sendJson(['status' => 'error', 'message' => 'APK file not found'], 404);
    }
    return $apkPath;
}

function downloadUrl(array $data): string
{
    $versionCode = (int)($data['versionCode'] ?? 0);
    $suffix = $versionCode > 0 ? '&versionCode=' . $versionCode : '';
    return (string)UPDATE_DOWNLOAD_URL . $suffix;
}

$data = loadUpdatePayload();

if (isset($_GET['download']) && (string)$_GET['download'] === '1') {
    $apkPath = apkPathFromPayload($data);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Length: ' . filesize($apkPath));
    header('Content-Disposition: attachment; filename="' . basename($apkPath) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($apkPath);
    exit;
}

$data['status'] = 'ok';
$data['apk'] = downloadUrl($data);
sendJson($data);
