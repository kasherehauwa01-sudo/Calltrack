<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/auth/AuthStore.kt');
$manifest = (string)file_get_contents($root . '/app/src/main/AndroidManifest.xml');
$backup = (string)file_get_contents($root . '/app/src/main/res/xml/backup_rules.xml');
$extraction = (string)file_get_contents($root . '/app/src/main/res/xml/data_extraction_rules.xml');
$unit = (string)file_get_contents($root . '/app/src/test/java/com/example/calltrack/auth/AuthStoreRecoveryTest.kt');
$login = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/auth/LoginActivity.kt');
$interceptor = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/auth/AuthInterceptor.kt');

foreach (['PREFERENCES_NAME = "android_auth"', 'MasterKey.DEFAULT_MASTER_KEY_ALIAS', 'context.deleteSharedPreferences(PREFERENCES_NAME)', 'KeyStore.getInstance("AndroidKeyStore")'] as $required) {
    if (!str_contains($store, $required)) throw new RuntimeException("Recovery не очищает минимальное auth-хранилище: {$required}");
}
foreach (['isRecoverableEncryptedStorageError', 'GeneralSecurityException', 'android.security.KeyStoreException', 'SecurityException', 'ProviderException', 'AndroidKeysetManager', 'EncryptedSharedPreferences'] as $required) {
    if (!str_contains($store, $required)) throw new RuntimeException("Не распознаётся crypto/keyset ошибка: {$required}");
}
foreach (['openWithSingleRecoveryAttempt', 'onRecoverableError', 'onRetryError', 'EncryptedAuthStorageException'] as $required) {
    if (!str_contains($store, $required)) throw new RuntimeException("Recovery не ограничен одной попыткой: {$required}");
}
if (!str_contains($store, 'encryptedPreferences.all')) throw new RuntimeException('AuthStore не проверяет расшифровку сохранённых значений при открытии');
foreach (['clearApplicationUserData', 'deleteDatabase', 'filesDir.deleteRecursively', 'cacheDir.deleteRecursively'] as $forbidden) {
    if (str_contains($store, $forbidden)) throw new RuntimeException("Recovery удаляет лишние данные: {$forbidden}");
}
if (!str_contains($manifest, 'android:allowBackup="true"') ||
    !str_contains($manifest, 'android:fullBackupContent="@xml/backup_rules"') ||
    !str_contains($manifest, 'android:dataExtractionRules="@xml/data_extraction_rules"')) {
    throw new RuntimeException('Manifest не подключает точечные backup rules');
}
foreach ([$backup, $extraction] as $rules) {
    if (!str_contains($rules, 'domain="sharedpref"') || !str_contains($rules, 'path="android_auth.xml"')) {
        throw new RuntimeException('Encrypted auth preferences не исключены из backup/transfer');
    }
    if (str_contains($rules, '<exclude domain="database"') || str_contains($rules, '<exclude domain="file"')) {
        throw new RuntimeException('Backup rules исключают данные, не относящиеся к AuthStore');
    }
}
foreach (['nestedCryptoErrorIsRecoverable', 'encryptedPreferencesSecurityExceptionIsRecoverable', 'unrelatedSecurityAndIllegalArgumentErrorsAreNotRecoverable', 'unrelatedRuntimeErrorIsNotRecoverable', 'successfulOpenDoesNotCleanup', 'cryptoFailureCleansOnlyOnceAndReturnsFreshStorage', 'retryFailureDoesNotStartRecoveryLoop', 'unrelatedFailureDoesNotDestroyStorage'] as $test) {
    if (!str_contains($unit, $test)) throw new RuntimeException("Нет unit-теста recovery: {$test}");
}
if (!str_contains($store, 'putString("token", token)') || !str_contains($store, 'fun clear() = prefs.edit().clear().apply()')) {
    throw new RuntimeException('Нарушено сохранение token или logout clear');
}
if (!str_contains($login, 'catch(error:EncryptedAuthStorageException)') || !str_contains($login, 'auth_storage_unavailable')) {
    throw new RuntimeException('Повторная ошибка recovery не обрабатывается контролируемо на экране входа');
}

if (!str_contains($interceptor, 'by lazy(LazyThreadSafetyMode.SYNCHRONIZED)')) {
    throw new RuntimeException('AuthInterceptor открывает Android Keystore во время создания Application');
}

echo "android_encrypted_auth_recovery_test: OK\n";
