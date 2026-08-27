<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gradle = (string)file_get_contents($root . '/app/build.gradle');
$ignore = (string)file_get_contents($root . '/.gitignore');
$example = (string)file_get_contents($root . '/keystore.properties.example');

foreach (['keystore.properties', 'signingConfigs', 'signingConfig signingConfigs.release', 'gradle.startParameter.taskNames'] as $expected) {
    if (!str_contains($gradle, $expected)) {
        throw new RuntimeException("Release-подпись не содержит обязательную настройку: {$expected}");
    }
}
foreach (['storeFile', 'storePassword', 'keyAlias', 'keyPassword'] as $key) {
    if (!str_contains($example, $key . '=')) {
        throw new RuntimeException("В примере отсутствует параметр подписи: {$key}");
    }
}
if (!str_contains($ignore, 'keystore.properties') || !str_contains($ignore, '*.jks')) {
    throw new RuntimeException('Секреты release-подписи не исключены из Git');
}

echo "android_release_signing_test: OK\n";
