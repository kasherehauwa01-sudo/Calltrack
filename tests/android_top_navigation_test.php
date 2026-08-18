<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mainLayout = (string)file_get_contents($root . '/app/src/main/res/layout/activity_main.xml');
$main = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
$aboutLayout = (string)file_get_contents($root . '/app/src/main/res/layout/activity_about.xml');
$about = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/main/AboutActivity.kt');
$analytics = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');

foreach (['btnTopBack', 'analyticsButtonContainer', 'notificationButtonContainer', 'btnSettings'] as $id) {
    if (!str_contains($mainLayout, 'android:id="@+id/' . $id . '"')) {
        throw new RuntimeException("В общей верхней строке отсутствует {$id}");
    }
}
foreach (['btnBack', 'btnAnalytics', 'btnNotifications', 'btnMenu'] as $id) {
    if (!str_contains($aboutLayout, 'android:id="@+id/' . $id . '"')) {
        throw new RuntimeException("На экране «О приложении» отсутствует {$id}");
    }
}
foreach (['R.drawable.ic_arrow_back', 'R.drawable.ic_analytics', 'R.drawable.ic_notifications', 'R.drawable.ic_more_vert'] as $icon) {
    if (!str_contains($analytics, $icon)) {
        throw new RuntimeException("На экране аналитики отсутствует {$icon}");
    }
}
if (!str_contains($main, 'binding.btnTopBack.setOnClickListener { onBackPressedDispatcher.onBackPressed() }') ||
    !str_contains($main, '.addToBackStack(null)')) {
    throw new RuntimeException('Верхняя стрелка не возвращает предыдущий Fragment');
}
if (!str_contains($about, 'binding.btnBack.setOnClickListener { finish() }') ||
    !str_contains($analytics, "getString(R.string.back)) {\n            finish()")) {
    throw new RuntimeException('Стрелка отдельного Activity не возвращает на предыдущий экран');
}
foreach (['EXTRA_OPEN_NOTIFICATIONS', 'EXTRA_OPEN_SETTINGS', 'EXTRA_OPEN_USER'] as $extra) {
    if (!str_contains($main, $extra)) {
        throw new RuntimeException("Главный экран не обрабатывает переход {$extra}");
    }
}

echo "android_top_navigation_test: OK\n";
