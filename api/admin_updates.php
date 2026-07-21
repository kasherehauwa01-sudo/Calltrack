<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function updatesDir(): string
{
    return dirname(__DIR__) . '/updates';
}

function safeApkFilename(string $versionName, int $versionCode, string $originalName): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $version = preg_replace('/[^A-Za-z0-9._-]+/', '_', $versionName);
    $base = trim((string)$base, '_') ?: 'Calltrack';
    return sprintf('%s_v%s_%d_%s.apk', $base, $version ?: 'version', $versionCode, date('YmdHis'));
}

function deleteUpdateFile(?string $filename): void
{
    $filename = basename((string)$filename);
    if ($filename === '') {
        return;
    }
    $path = updatesDir() . '/' . $filename;
    $realDir = realpath(updatesDir());
    $realFile = realpath($path);
    if ($realDir !== false && $realFile !== false && strpos($realFile, $realDir) === 0 && is_file($realFile)) {
        @unlink($realFile);
    }
}

function sendUpdateApk(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        sendJson(['status' => 'error', 'message' => 'Передайте id обновления'], 400);
    }

    $stmt = $pdo->prepare('SELECT filename FROM app_updates WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['status' => 'error', 'message' => 'Обновление не найдено'], 404);
    }

    $filename = basename((string)($row['filename'] ?? ''));
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'apk') {
        sendJson(['status' => 'error', 'message' => 'APK filename is invalid'], 500);
    }

    $updatesDir = realpath(updatesDir());
    $apkPath = realpath(updatesDir() . '/' . $filename);
    if ($updatesDir === false || $apkPath === false || strpos($apkPath, $updatesDir) !== 0 || !is_file($apkPath)) {
        sendJson(['status' => 'error', 'message' => 'APK file not found'], 404);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($apkPath));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($apkPath);
    exit;
}

function nextUpdateVersion(PDO $pdo): array
{
    $row = $pdo->query('SELECT version_name, version_code FROM app_updates ORDER BY version_code DESC, uploaded_at DESC, id DESC LIMIT 1')->fetch();
    $nextCode = (int)($row['version_code'] ?? 0) + 1;
    $previousName = (string)($row['version_name'] ?? '');
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $previousName, $matches)) {
        $versionName = $matches[1] . '.' . $matches[2] . '.' . ((int)$matches[3] + 1);
    } else {
        $versionName = '1.0.' . $nextCode;
    }
    return ['version_name' => $versionName, 'version_code' => $nextCode];
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function generateUpdateJson(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT * FROM app_updates ORDER BY version_code DESC, uploaded_at DESC, id DESC LIMIT 1');
    $row = $stmt->fetch();
    $jsonPath = updatesDir() . '/update.json';
    if (!$row) {
        if (is_file($jsonPath)) {
            @unlink($jsonPath);
        }
        return;
    }
    $notes = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($row['release_notes'] ?? '')) ?: []), static fn(string $line): bool => $line !== ''));
    $payload = [
        'status' => 'ok',
        'versionName' => (string)$row['version_name'],
        'versionCode' => (int)$row['version_code'],
        'mandatory' => (bool)$row['mandatory'],
        'apk' => (string)UPDATE_DOWNLOAD_URL . '&versionCode=' . (int)$row['version_code'],
        'filename' => (string)$row['filename'],
        'releaseDate' => (string)$row['uploaded_at'],
        'releaseNotes' => $notes,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || @file_put_contents($jsonPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сформировать update.json');
    }
}

function listUpdates(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT id, filename, version_name, version_code, release_notes, mandatory, file_size, uploaded_at FROM app_updates ORDER BY version_code DESC, uploaded_at DESC, id DESC');
    sendJson(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

try {
    $pdo = getPdo();
    ensureAppUpdatesTable($pdo);
    $dir = updatesDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог updates');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ((string)($_GET['action'] ?? '') === 'download') {
            sendUpdateApk($pdo, (int)($_GET['id'] ?? 0));
        }
        listUpdates($pdo);
    }

    $isJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $data = $isJson ? readJsonBody() : $_POST;
    $action = (string)($data['action'] ?? 'save');

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            sendJson(['status' => 'error', 'message' => 'Передайте id обновления'], 400);
        }
        $stmt = $pdo->prepare('SELECT filename FROM app_updates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            sendJson(['status' => 'error', 'message' => 'Обновление не найдено'], 404);
        }
        $delete = $pdo->prepare('DELETE FROM app_updates WHERE id = :id');
        $delete->execute([':id' => $id]);
        deleteUpdateFile($row['filename'] ?? '');
        generateUpdateJson($pdo);
        sendJson(['status' => 'success', 'deleted' => $delete->rowCount()]);
    }

    if ($action === 'chunk_upload') {
        $uploadId = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($data['upload_id'] ?? ''));
        $chunkIndex = (int)($data['chunk_index'] ?? -1);
        $totalChunks = (int)($data['total_chunks'] ?? 0);
        $originalName = (string)($data['filename'] ?? '');
        $releaseNotes = trim((string)($data['release_notes'] ?? ''));
        $id = (int)($data['id'] ?? 0);
        if ($uploadId === '' || $chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
            sendJson(['status' => 'error', 'message' => 'Некорректные параметры chunk upload'], 400);
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'apk') {
            sendJson(['status' => 'error', 'message' => 'Можно загружать только .apk файлы'], 400);
        }
        $chunk = $_FILES['chunk'] ?? null;
        if (!is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($chunk['tmp_name'] ?? ''))) {
            sendJson(['status' => 'error', 'message' => 'Часть APK не была загружена'], 400);
        }
        $chunksRoot = $dir . '/.chunks';
        $chunkDir = $chunksRoot . '/' . $uploadId;
        if (!is_dir($chunkDir) && !@mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
            throw new RuntimeException('Не удалось создать временный каталог загрузки');
        }
        $chunkPath = $chunkDir . '/' . $chunkIndex . '.part';
        if (!@move_uploaded_file((string)$chunk['tmp_name'], $chunkPath)) {
            sendJson(['status' => 'error', 'message' => 'Не удалось сохранить часть APK'], 500);
        }
        @chmod($chunkPath, 0644);
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!is_file($chunkDir . '/' . $i . '.part')) {
                sendJson(['status' => 'success', 'complete' => false, 'chunk' => $chunkIndex + 1, 'total' => $totalChunks]);
            }
        }

        $current = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM app_updates WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch();
            if (!$current) {
                cleanupDir($chunkDir);
                sendJson(['status' => 'error', 'message' => 'Обновление не найдено'], 404);
            }
        }
        $autoVersion = nextUpdateVersion($pdo);
        $versionName = $current ? (string)$current['version_name'] : $autoVersion['version_name'];
        $versionCode = $current ? (int)$current['version_code'] : $autoVersion['version_code'];
        $newFilename = safeApkFilename($versionName, $versionCode, $originalName);
        $target = $dir . '/' . $newFilename;
        $out = @fopen($target, 'wb');
        if (!$out) {
            cleanupDir($chunkDir);
            sendJson(['status' => 'error', 'message' => 'Не удалось собрать APK на сервере'], 500);
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $in = @fopen($chunkDir . '/' . $i . '.part', 'rb');
            if (!$in) {
                @fclose($out);
                @unlink($target);
                cleanupDir($chunkDir);
                sendJson(['status' => 'error', 'message' => 'Не удалось прочитать часть APK'], 500);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        @chmod($target, 0644);
        cleanupDir($chunkDir);
        if ($current && !empty($current['filename'])) {
            deleteUpdateFile($current['filename']);
        }
        $fileSize = @filesize($target) ?: 0;
        $uploadedAt = date('Y-m-d H:i:s');
        if ($current) {
            $stmt = $pdo->prepare('UPDATE app_updates SET filename=:filename, release_notes=:release_notes, mandatory=0, file_size=:file_size, uploaded_at=:uploaded_at WHERE id=:id');
            $stmt->execute([':filename' => $newFilename, ':release_notes' => $releaseNotes, ':file_size' => $fileSize, ':uploaded_at' => $uploadedAt, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO app_updates (filename, version_name, version_code, release_notes, mandatory, file_size, uploaded_at) VALUES (:filename, :version_name, :version_code, :release_notes, 0, :file_size, :uploaded_at)');
            $stmt->execute([':filename' => $newFilename, ':version_name' => $versionName, ':version_code' => $versionCode, ':release_notes' => $releaseNotes, ':file_size' => $fileSize, ':uploaded_at' => $uploadedAt]);
            $id = (int)$pdo->lastInsertId();
        }
        generateUpdateJson($pdo);
        sendJson(['status' => 'success', 'complete' => true, 'id' => $id]);
    }

    $id = (int)($data['id'] ?? 0);
    $autoVersion = nextUpdateVersion($pdo);
    $versionName = trim((string)($data['version_name'] ?? ''));
    $versionCode = (int)($data['version_code'] ?? 0);
    $releaseNotes = trim((string)($data['release_notes'] ?? ''));
    $mandatory = 0;

    $current = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM app_updates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetch();
        if (!$current) {
            sendJson(['status' => 'error', 'message' => 'Обновление не найдено'], 404);
        }
    }
    if ($current) {
        $versionName = $versionName !== '' ? $versionName : (string)$current['version_name'];
        $versionCode = $versionCode > 0 ? $versionCode : (int)$current['version_code'];
    } else {
        $versionName = $autoVersion['version_name'];
        $versionCode = $autoVersion['version_code'];
    }

    $filename = $current['filename'] ?? '';
    $fileSize = (int)($current['file_size'] ?? 0);
    $uploadedAt = $current['uploaded_at'] ?? date('Y-m-d H:i:s');
    $file = $_FILES['apk'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            sendJson(['status' => 'error', 'message' => 'Ошибка загрузки APK'], 400);
        }
        $originalName = (string)($file['name'] ?? '');
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'apk') {
            sendJson(['status' => 'error', 'message' => 'Можно загружать только .apk файлы'], 400);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            sendJson(['status' => 'error', 'message' => 'APK не был загружен'], 400);
        }
        $newFilename = safeApkFilename($versionName, $versionCode, $originalName);
        $target = $dir . '/' . $newFilename;
        if (!@move_uploaded_file($tmp, $target)) {
            sendJson(['status' => 'error', 'message' => 'Не удалось сохранить APK'], 500);
        }
        @chmod($target, 0644);
        if ($filename !== '') {
            deleteUpdateFile($filename);
        }
        $filename = $newFilename;
        $fileSize = @filesize($target) ?: 0;
        $uploadedAt = date('Y-m-d H:i:s');
    } elseif ($id <= 0) {
        sendJson(['status' => 'error', 'message' => 'Загрузите APK файл. Если файл выбран, проверьте upload_max_filesize и post_max_size на сервере'], 400);
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE app_updates SET filename=:filename, version_name=:version_name, version_code=:version_code, release_notes=:release_notes, mandatory=:mandatory, file_size=:file_size, uploaded_at=:uploaded_at WHERE id=:id');
        $stmt->execute([':filename'=>$filename, ':version_name'=>$versionName, ':version_code'=>$versionCode, ':release_notes'=>$releaseNotes, ':mandatory'=>$mandatory, ':file_size'=>$fileSize, ':uploaded_at'=>$uploadedAt, ':id'=>$id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO app_updates (filename, version_name, version_code, release_notes, mandatory, file_size, uploaded_at) VALUES (:filename, :version_name, :version_code, :release_notes, :mandatory, :file_size, :uploaded_at)');
        $stmt->execute([':filename'=>$filename, ':version_name'=>$versionName, ':version_code'=>$versionCode, ':release_notes'=>$releaseNotes, ':mandatory'=>$mandatory, ':file_size'=>$fileSize, ':uploaded_at'=>$uploadedAt]);
        $id = (int)$pdo->lastInsertId();
    }
    generateUpdateJson($pdo);
    sendJson(['status' => 'success', 'id' => $id]);
} catch (Throwable $e) {
    error_log($e);
    sendJson([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 500);
}
