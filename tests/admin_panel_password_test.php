<?php
declare(strict_types=1);

$html = (string)file_get_contents(dirname(__DIR__) . '/analizmop/index.html');

if (!str_contains($html, "const ADMIN_PASSWORD='8852285';")) {
    throw new RuntimeException('Для вкладки админ-панели не установлен ожидаемый пароль');
}
if (!str_contains($html, 'if(value!==ADMIN_PASSWORD)')) {
    throw new RuntimeException('Вход в админ-панель не проверяет введённый пароль');
}
if (str_contains($html, 'adminEls.password.value=ADMIN_PASSWORD')) {
    throw new RuntimeException('Пароль админ-панели не должен подставляться автоматически');
}

echo "admin_panel_password_test: OK\n";
