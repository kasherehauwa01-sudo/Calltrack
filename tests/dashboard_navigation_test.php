<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$html = (string)file_get_contents($root . '/analizmop/index.html');
foreach (['<button class="tab-btn" data-tab="help"', 'id="helpView"', '>Помощь</button>', 'helpView.classList'] as $removed) {
    if (str_contains($html, $removed)) {
        throw new RuntimeException("Вкладка помощи не удалена полностью: {$removed}");
    }
}

$standaloneDashboard = (string)file_get_contents($root . '/dashboard/index.html');
if (str_contains($standaloneDashboard, 'Помощь')) {
    throw new RuntimeException('Вкладка помощи осталась в standalone-дашборде');
}

$apiJs = (string)file_get_contents($root . '/analizmop/api.js');
foreach (['function removeLegacyHelpTab()', "document.addEventListener('DOMContentLoaded', removeLegacyHelpTab"] as $required) {
    if (!str_contains($apiJs, $required)) {
        throw new RuntimeException("Не найдена защита от закэшированной вкладки помощи: {$required}");
    }
}

if (!str_contains($html, 'api.js?v=20260827-client-email-timeline')) {
    throw new RuntimeException('Не обновлена версия подключаемого JavaScript');
}

echo "dashboard_navigation_test: OK\n";
