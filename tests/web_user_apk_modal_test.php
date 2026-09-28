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
if (!str_contains($html, "String(item.user_phone||'')===`web-user-\${webUser.id}`")) {
    throw new RuntimeException('APK-информация не связана с учётной записью web-пользователя');
}
if (!str_contains($html, '.apk-user-dialog{width:100%;max-width:none;min-width:0;')) {
    throw new RuntimeException('Окно APK не занимает всю ширину экрана');
}
foreach (['#apkUserModal{padding:0;overflow-x:hidden}', '.apk-user-dialog .user-card{width:100%;', '.user-logs{max-width:100%;'] as $noHorizontalScroll) {
    if (!str_contains($html, $noHorizontalScroll)) {
        throw new RuntimeException("Не отключена горизонтальная прокрутка окна APK: {$noHorizontalScroll}");
    }
}

echo "web_user_apk_modal_test: OK\n";
