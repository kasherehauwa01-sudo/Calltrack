<?php
declare(strict_types=1);

$html = (string)file_get_contents(dirname(__DIR__) . '/analizmop/index.html');

foreach (['data-web-user-apk=', 'id="apkUserModal"', 'function openApkUserModal(', 'renderUserCard();'] as $required) {
    if (!str_contains($html, $required)) {
        throw new RuntimeException("Не реализовано окно APK пользователя: {$required}");
    }
}
foreach (["u.role==='manager'?", "if(app)openApkUserModal(app.dataset.webUserApk)", "if(!webUser||webUser.role!=='manager') return", 'Приложение: ${webUser.display_name}'] as $required) {
    if (!str_contains($html, $required)) {
        throw new RuntimeException("Кнопка приложения не ограничена web-менеджерами: {$required}");
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
if (!str_contains($html, '#apkUserModal .apk-user-dialog{width:100vw;max-width:100vw;min-width:0;height:100vh;max-height:100vh;')) {
    throw new RuntimeException('Окно APK не занимает всю ширину экрана');
}
foreach (['#apkUserModal{padding:0;overflow:hidden}', '.apk-user-dialog .user-card{width:100%;', '.user-logs{max-width:100%;'] as $noHorizontalScroll) {
    if (!str_contains($html, $noHorizontalScroll)) {
        throw new RuntimeException("Не отключена горизонтальная прокрутка окна APK: {$noHorizontalScroll}");
    }
}
foreach (['class="user-action-help"', 'ставит команду в очередь', 'При следующем соединении Android-приложение', 'ещё не синхронизированные звонки'] as $explanation) {
    if (!str_contains($html, $explanation)) {
        throw new RuntimeException("Не объяснено действие принудительной синхронизации: {$explanation}");
    }
}

echo "web_user_apk_modal_test: OK\n";
