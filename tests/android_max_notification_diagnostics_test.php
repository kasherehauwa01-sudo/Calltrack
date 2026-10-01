<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$manifest=(string)file_get_contents($root.'/app/src/main/AndroidManifest.xml');
$service=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/notification/MaxNotificationListenerService.kt');
$permissions=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/permissions/AppPermissions.kt');
$fragment=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/main/PermissionsFragment.kt');
$onboarding=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/onboarding/PermissionsOnboardingActivity.kt');
$layout=(string)file_get_contents($root.'/app/src/main/res/layout/activity_permissions_onboarding.xml');
$strings=(string)file_get_contents($root.'/app/src/main/res/values/strings.xml');

function maxAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
foreach (['.notification.MaxNotificationListenerService','android.permission.BIND_NOTIFICATION_LISTENER_SERVICE','android.service.notification.NotificationListenerService'] as $required)maxAssert(str_contains($manifest,$required),"Manifest MAX listener: {$required}");
foreach (['MAX_PACKAGE = "ru.oneme.app"','TAG = "CalltrackMAX"','android.callType','android.callIsVideo','android.callPerson','Notification\\$CallStyle','getParcelable(CALL_PERSON_KEY, Person::class.java)','Build.VERSION_CODES.TIRAMISU','runCatching'] as $required)maxAssert(str_contains($service,$required),"Обработчик MAX не содержит: {$required}");
foreach (['sessionCreated','contactResolved','callAnswered','callEnded','callQueued','callUploaded','callUploadFailed','MaxCallStateMachine.posted','MaxContactResolver','source = "max"','sourceEventId = result.sourceEventId','repository.saveCall(entity)','repository.syncCallById(id)','CalltrackRecoveryManager.schedulePendingSync','sessionStore.loadCompleted()'] as $required)maxAssert(str_contains($service,$required),"Production pipeline MAX не содержит: {$required}");
foreach (['SQL_API_BASE_URL','OkHttp','File(','AppLogger'] as $forbidden)maxAssert(!str_contains($service,$forbidden),"Listener MAX обходит общий repository pipeline: {$forbidden}");
foreach (['NotificationManagerCompat.getEnabledListenerPackages','hasNotificationListenerAccess'] as $required)maxAssert(str_contains($permissions,$required),"Нет проверки Notification Access: {$required}");
maxAssert(str_contains($fragment,'Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS'),'Экран разрешений не открывает Notification Access');
foreach (['ACTION_NOTIFICATION_LISTENER_SETTINGS','refreshNotificationAccess()','btnNotificationAccess'] as $required)maxAssert(str_contains($onboarding.$layout,$required),"Первичная настройка MAX отсутствует: {$required}");
foreach (['Доступ к уведомлениям','Нужен для регистрации звонков и сообщений MAX.'] as $required)maxAssert(str_contains($strings,$required),"Нет текста разрешения MAX: {$required}");

echo "android_max_notification_diagnostics_test: OK\n";
