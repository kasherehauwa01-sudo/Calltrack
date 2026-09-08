<?php
declare(strict_types=1);

$html = (string)file_get_contents(dirname(__DIR__) . '/analizmop/index.html');

foreach (['data-web-user-apk=', 'id="apkUserModal"', 'function openApkUserModal(', 'renderUserCard();'] as $required) {
    if (!str_contains($html, $required)) {
        throw new RuntimeException("Не реализовано окно APK пользователя: {$required}");
    }
}
foreach (['<h3>Пользователи Android-приложения</h3>', 'id="usersSearch"', 'id="usersList"'] as $removed) {
    if (str_contains($html, $removed)) {
        throw new RuntimeException("Отдельный блок Android-пользователей не удалён: {$removed}");
    }
}
if (!str_contains($html, "String(item.user_phone||'')===String(webUser.manager_user_phone)")) {
    throw new RuntimeException('APK-информация не связана с менеджером web-пользователя');
}

echo "web_user_apk_modal_test: OK\n";
