<?php
declare(strict_types=1);
$root=dirname(__DIR__);$server=file_get_contents($root.'/api/android_auth.php');$endpoint=file_get_contents($root.'/api/android_auth_api.php');$users=file_get_contents($root.'/api/web_users.php');$app=file_get_contents($root.'/app/src/main/java/com/example/calltrack/auth/AuthStore.kt');$client=file_get_contents($root.'/app/src/main/java/com/example/calltrack/auth/AndroidAuthClient.kt');$login=file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/auth/LoginActivity.kt');$repo=file_get_contents($root.'/app/src/main/java/com/example/calltrack/data/repository/CallRepository.kt');
foreach(['web_user_tokens','token_hash CHAR(64)','hash(\'sha256\',$raw)','random_bytes(32)','password_verify($pin','expires_at>NOW()','revoked_at IS NULL'] as $required)if(!str_contains($server.$endpoint,$required))throw new RuntimeException("Android auth не содержит: $required");
foreach(['EncryptedSharedPreferences','MasterKey.KeyScheme.AES256_GCM','putString("token", token)','putString("login", login)','prefs.edit().clear()'] as $required)if(!str_contains($app,$required))throw new RuntimeException("Данные авторизации хранятся неверно: $required");
if(str_contains($app,'pin')||str_contains($client,'putString("pin"'))throw new RuntimeException('PIN сохраняется на Android');
foreach(['action=login','action=me','action=logout','AuthInterceptor(appContext)','LoginActivity','PIN-код'] as $required)if(!str_contains($client.$login.$repo.file_get_contents($root.'/app/src/main/res/layout/activity_login.xml'),$required))throw new RuntimeException("Android-клиент не содержит: $required");
foreach(['webUserPhone((int)$user[\'id\'])',"user['user_phone']=webUserPhone((int)\$user['id'])"] as $required)if(!str_contains($server.$endpoint,$required))throw new RuntimeException("Android не использует идентификатор общей учётной записи: $required");
foreach(['Нельзя удалить последнего активного администратора','Нельзя изменить роль или отключить последнего активного администратора','revokeAndroidTokens'] as $required)if(!str_contains($users,$required))throw new RuntimeException("Нет защиты учётных записей: $required");
foreach(['add_call.php','personal_contact.php','user_report.php'] as $file){$source=file_get_contents($root.'/api/'.$file);if(!str_contains($source,'optionalAndroidUser($pdo)')||!str_contains($source,"\$data['user_phone']=\$identity['user_phone']"))throw new RuntimeException("Нет token scope: $file");}
$userLayout=file_get_contents($root.'/app/src/main/res/layout/fragment_user.xml');$userFragment=file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/main/UserFragment.kt');
if(!str_contains($userLayout,'android:text="Логин"')||!str_contains($userLayout,'tvCurrentUserLogin')||str_contains($userLayout,'Номер телефона')||str_contains($userLayout,'btnSwitchUser')||!str_contains($userFragment,'AuthStore(requireContext()).login'))throw new RuntimeException('Экран пользователя не показывает login авторизованной учётной записи');
$onboarding=file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/onboarding/OnboardingFragment.kt');$onboardingLayout=file_get_contents($root.'/app/src/main/res/layout/fragment_onboarding.xml');
foreach(['etManager','etManagerPhone','submitManagerName','Stage.AUTH'] as $removed)if(str_contains($onboarding.$onboardingLayout,$removed))throw new RuntimeException("После входа осталось ручное заполнение профиля: $removed");
foreach(['Stage.COMPLETE','host.completeOnboarding()'] as $required)if(!str_contains($onboarding,$required))throw new RuntimeException("Первоначальная настройка не завершается автоматически: $required");
$androidManifest=file_get_contents($root.'/app/src/main/AndroidManifest.xml');$gradle=file_get_contents($root.'/app/build.gradle');
$androidFiles=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/src/main',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){if($file->isFile()){$androidFiles[]=$file->getPathname();}}
$androidSource=$androidManifest.$gradle.implode('',array_map(static fn(string $file):string=>(string)file_get_contents($file),$androidFiles));
foreach(['btnBarcodeScanner','BarcodeScannerActivity','ic_barcode_scanner','zxing-android-embedded','FloatingActionButton'] as $removed){
    if(str_contains($androidSource,$removed))throw new RuntimeException("В APK осталась кнопка или функция сканера: {$removed}");
}
echo "android_shared_auth_test: OK\n";
