<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$auth=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/auth/AndroidAuthClient.kt');
$login=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/auth/LoginActivity.kt');
$main=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$permissions=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/main/PermissionsFragment.kt');

if(str_contains($auth,'CalltrackRecoveryManager.recover('))throw new RuntimeException('Foreground recovery всё ещё запускается внутри фоновой coroutine авторизации');
if(!str_contains($login,'auth.login(login,pin).onSuccess{openApp()}'))throw new RuntimeException('Успешная авторизация не открывает MainActivity');
$onCreate=substr($main,strpos($main,'override fun onCreate'),strpos($main,'override fun onNewIntent')-strpos($main,'override fun onCreate'));
if(str_contains($onCreate,'requestUnknownAppsPermissionIfNeeded'))throw new RuntimeException('MainActivity автоматически уводит пользователя на системный экран после авторизации');
if(!str_contains($main,'CalltrackRecoveryManager.recover(this, RecoveryReason.APP_START)'))throw new RuntimeException('Recovery не запускается штатно после onboarding');
if(!str_contains($permissions,'Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES'))throw new RuntimeException('Явная выдача разрешения на установку APK пропала с экрана разрешений');

echo "android_post_login_startup_test: OK\n";
