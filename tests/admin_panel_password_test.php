<?php
declare(strict_types=1);

$html = (string)file_get_contents(dirname(__DIR__) . '/analizmop/index.html');

foreach (['ADMIN_PASSWORD','adminPasswordValue','adminPassword','adminLoginBtn','X-Calltrack-Admin-Password'] as $forbidden) {
    if (str_contains($html, $forbidden)) {
        throw new RuntimeException("Frontend содержит общий admin-секрет: {$forbidden}");
    }
}

echo "admin_panel_password_test: OK\n";
