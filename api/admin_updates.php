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
        'versionName' => (string)$row['version_name'],
        'versionCode' => (int)$row['version_code'],
        'mandatory' => (bool)$row['mandatory'],
        'apk' => rtrim((string)UPDATE_PUBLIC_BASE, '/') . '/' . rawurlencode((string)$row['filename']),
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
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
