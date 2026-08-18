<?php
declare(strict_types=1);

$html = (string)file_get_contents(dirname(__DIR__) . '/analizmop/index.html');
foreach (['data-tab="help"', 'id="helpView"', '>Помощь</button>', 'helpView.classList'] as $removed) {
    if (str_contains($html, $removed)) {
        throw new RuntimeException("Вкладка помощи не удалена полностью: {$removed}");
    }
}

echo "dashboard_navigation_test: OK\n";
