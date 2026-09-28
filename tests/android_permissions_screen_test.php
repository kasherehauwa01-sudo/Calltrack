<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$settingsLayout = (string)file_get_contents($root . '/app/src/main/res/layout/fragment_settings.xml');
$settingsFragment = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/SettingsFragment.kt');
$permissionsLayout = (string)file_get_contents($root . '/app/src/main/res/layout/fragment_permissions.xml');
$permissionItem = (string)file_get_contents($root . '/app/src/main/res/layout/item_permission.xml');
$permissionsFragment = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/PermissionsFragment.kt');
$main = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$strings = (string)file_get_contents($root . '/app/src/main/res/values/strings.xml');

foreach (['btnPermissions', '@string/permissions', '@string/permissions_summary'] as $required) {
    if (!str_contains($settingsLayout, $required)) throw new RuntimeException("В настройках отсутствует раздел разрешений: {$required}");
}
if (!str_contains($settingsFragment, 'binding.btnPermissions.setOnClickListener') || !str_contains($settingsFragment, 'openPermissionsScreen()')) {
    throw new RuntimeException('Раздел разрешений не открывает отдельный экран');
}
foreach (['permissionsContainer', '@string/permissions_explanation', 'btnBack'] as $required) {
    if (!str_contains($permissionsLayout, $required)) throw new RuntimeException("Экран разрешений не содержит: {$required}");
}
foreach (['tvPermissionTitle', 'tvPermissionDescription', 'tvPermissionStatus', 'btnGrant', '@string/grant_permission'] as $required) {
    if (!str_contains($permissionItem, $required)) throw new RuntimeException("Строка разрешения не содержит: {$required}");
}
foreach (['READ_PHONE_STATE', 'READ_CALL_LOG', 'READ_CONTACTS', 'CALL_PHONE', 'POST_NOTIFICATIONS', 'ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS', 'ACTION_MANAGE_UNKNOWN_APP_SOURCES'] as $required) {
    if (!str_contains($permissionsFragment, $required)) throw new RuntimeException("Не проверяется обязательное разрешение: {$required}");
}
foreach (['RequestPermission()', 'row.btnGrant.visibility = if (granted) View.GONE else View.VISIBLE', 'permission_granted', 'permission_not_granted', 'onResume()', 'refreshPermissions()'] as $required) {
    if (!str_contains($permissionsFragment, $required)) throw new RuntimeException("Нет обновления или выдачи разрешений: {$required}");
}
foreach (['Отозвать', 'revokeRuntimePermission'] as $forbidden) {
    if (str_contains($permissionsFragment . $permissionsLayout . $permissionItem . $strings, $forbidden)) {
        throw new RuntimeException("Экран позволяет отзывать разрешение: {$forbidden}");
    }
}
if (!str_contains($main, 'fun openPermissionsScreen()') || !str_contains($main, 'is PermissionsFragment')) {
    throw new RuntimeException('MainActivity не поддерживает навигацию экрана разрешений');
}

echo "android_permissions_screen_test: OK\n";
