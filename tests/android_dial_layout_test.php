<?php
declare(strict_types=1);

$layout = (string)file_get_contents(dirname(__DIR__) . '/app/src/main/res/layout/fragment_dial_pad.xml');
$results = strpos($layout, 'android:id="@+id/recyclerT9"');
$number = strpos($layout, 'android:id="@+id/tvNumber"');
$keypad = strpos($layout, '<GridLayout');
if ($results === false || $number === false || $keypad === false || !($results < $number && $number < $keypad)) {
    throw new RuntimeException('Результаты поиска должны быть выше номера и клавиатуры');
}
$activity = (string)file_get_contents(dirname(__DIR__) . '/app/src/main/java/com/example/calltrack/ui/main/MainActivity.kt');
if (!str_contains($activity, 'navigationInitialBottomPadding + bars.bottom')) {
    throw new RuntimeException('Нижняя панель не учитывает системную навигационную область');
}
if (!str_contains($activity, 'root.setPadding(0, 0, 0, 0)')) {
    throw new RuntimeException('Общий нижний inset всё ещё отрывает фон панели от края экрана');
}

echo "android_dial_layout_test: OK\n";
