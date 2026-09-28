<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = (string) file_get_contents($root . '/app/src/main/AndroidManifest.xml');
$onboarding = (string) file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/onboarding/PermissionsOnboardingActivity.kt');
$store = (string) file_get_contents($root . '/app/src/main/java/com/example/calltrack/permissions/PermissionOnboardingStore.kt');
$permissions = (string) file_get_contents($root . '/app/src/main/java/com/example/calltrack/permissions/AppPermissions.kt');
$dialPad = (string) file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/dialpad/DialPadFragment.kt');

if (!str_contains($manifest, '.ui.onboarding.PermissionsOnboardingActivity') ||
    !preg_match('/PermissionsOnboardingActivity[\s\S]+android\.intent\.action\.MAIN/', $manifest)) {
    throw new RuntimeException('Permissions onboarding is not the launcher activity');
}
foreach (['RequestMultiplePermissions()', 'missingRuntimePermissions', 'finishOnboarding()', 'ACTION_APPLICATION_DETAILS_SETTINGS'] as $required) {
    if (!str_contains($onboarding, $required)) throw new RuntimeException("Onboarding is missing: {$required}");
}
if (!str_contains($store, 'permissions_onboarding_completed')) {
    throw new RuntimeException('Onboarding completion flag is not persisted');
}
foreach (['READ_PHONE_STATE', 'READ_CALL_LOG', 'READ_CONTACTS', 'CALL_PHONE', 'TIRAMISU', 'POST_NOTIFICATIONS'] as $required) {
    if (!str_contains($permissions, $required)) throw new RuntimeException("Runtime permission list is missing: {$required}");
}
foreach (['checkSelfPermission', 'READ_CONTACTS', 'catch (_: SecurityException)', 'emptyList()'] as $required) {
    if (!str_contains($dialPad, $required)) throw new RuntimeException("Dial pad contact fallback is missing: {$required}");
}

echo "android_runtime_permissions_test: OK\n";
