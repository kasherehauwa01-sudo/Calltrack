<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/app/src/main/res/layout/fragment_settings.xml');
$fragment = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/SettingsFragment.kt');

foreach (['android:id="@+id/btnBack"', 'android:src="@drawable/ic_arrow_back"', 'android:contentDescription="@string/back"'] as $expected) {
    if (!str_contains($layout, $expected)) {
        throw new RuntimeException("На экране настроек отсутствует элемент: {$expected}");
    }
}

if (!str_contains($fragment, 'binding.btnBack.setOnClickListener') ||
    !str_contains($fragment, 'onBackPressedDispatcher.onBackPressed()')) {
    throw new RuntimeException('Стрелка назад на экране настроек не возвращает на предыдущий экран');
}

echo "android_settings_layout_test: OK\n";
