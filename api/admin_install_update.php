<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function assertInstallUpdateAdmin(): void
{
    $provided = (string)($_SERVER['HTTP_X_CALLTRACK_ADMIN_PASSWORD'] ?? '');
    if ($provided === '' || !hash_equals((string)CALLTRACK_ADMIN_PASSWORD, $provided)) {
        sendJson(['status' => 'error', 'message' => 'Требуется авторизация администратора'], 403);
    }
}

try {
    assertInstallUpdateAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJson(['status' => 'error', 'message' => 'Разрешён только POST'], 405);
    }

    $pdo = getPdo();
    ensureUserTelemetryTables($pdo);
    ensureAppUpdatesTable($pdo);
    $latest = $pdo->query('SELECT version_name, version_code FROM app_updates ORDER BY version_code DESC, uploaded_at DESC, id DESC LIMIT 1')->fetch();
    if (!$latest) {
        sendJson(['status' => 'error', 'message' => 'Нет загруженного APK'], 404);
    }

    $versionCode = (int)$latest['version_code'];
    $versionName = (string)$latest['version_name'];
    $eligible = $pdo->prepare(
        'SELECT user_phone FROM app_user_reports WHERE user_phone <> \'\' AND app_version_code IS NOT NULL AND app_version_code < :version_code'
    );
    $eligible->execute([':version_code' => $versionCode]);
    $phones = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['user_phone'], $eligible->fetchAll())));

    $insert = $pdo->prepare(
        "INSERT INTO app_user_commands (user_phone, command, target_version_code, target_version_name, status)
         SELECT :user_phone, 'install_update', :version_code, :version_name, 'pending'
         WHERE NOT EXISTS (
             SELECT 1 FROM app_user_commands
             WHERE user_phone = :check_phone AND command = 'install_update'
               AND target_version_code = :check_version AND status = 'pending'
         )"
    );
    $queued = 0;
    foreach ($phones as $phone) {
        $insert->execute([
            ':user_phone' => $phone,
            ':version_code' => $versionCode,
            ':version_name' => $versionName,
            ':check_phone' => $phone,
            ':check_version' => $versionCode,
        ]);
        $queued += $insert->rowCount();
    }

    $unknown = (int)$pdo->query('SELECT COUNT(*) FROM app_user_reports WHERE app_version_code IS NULL')->fetchColumn();
    sendJson([
        'status' => 'success',
        'message' => 'Уведомления поставлены в очередь',
        'version_name' => $versionName,
        'version_code' => $versionCode,
        'eligible_users' => count($phones),
        'queued' => $queued,
        'without_version_code' => $unknown,
    ]);
} catch (Throwable $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
}
