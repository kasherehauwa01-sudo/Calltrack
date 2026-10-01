<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$adapter=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/calls/CallAdapter.kt');
$list=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/calls/CallListFragment.kt');
$layout=(string)file_get_contents($root.'/app/src/main/res/layout/item_call.xml');
$analytics=(string)file_get_contents($root.'/app/src/main/java/com/example/calltrack/ui/analytics/AnalyticsActivity.kt');

foreach (['item.call.source.equals("max"','Color.argb(MAX_BACKGROUND_ALPHA, 145, 50, 213)','(\\u041C\\u0410\\u041A\\u0421)','binding.btnComment','binding.btnReminder'] as $required) {
    if(!str_contains($adapter,$required))throw new RuntimeException("История звонков не содержит MAX-оформление: {$required}");
}
if(!str_contains($adapter,'MAX_BACKGROUND_ALPHA = 13'))throw new RuntimeException('Фиолетовый фон MAX-звонка должен иметь прозрачность 5%');
if(!str_contains($layout,'app:strokeWidth="0dp"'))throw new RuntimeException('С плашки MAX-звонка не убрана обводка');
if(!str_contains($list,'call.contactName.ifBlank { call.phone }'))throw new RuntimeException('История не использует имя абонента MAX');
foreach (['tvName','tvType','btnComment','btnReminder'] as $required)if(!str_contains($layout,$required))throw new RuntimeException("В компактной плашке отсутствует {$required}");
foreach (['tvClient1c','tvPhone','tvNote'] as $removed)if(str_contains($layout,$removed))throw new RuntimeException("В компактной плашке осталось лишнее поле {$removed}");
foreach (['\\u0418\\u0441\\u0442\\u043E\\u0440\\u0438\\u044F \\u043A\\u043B\\u0438\\u0435\\u043D\\u0442\\u043E\\u0432','source = row.optString("source")','managerEmails','managerSales','Color.rgb(145, 50, 213)','MAX \\u2022'] as $required) {
    if(!str_contains($analytics,$required))throw new RuntimeException("История клиентов не содержит обязательный элемент: {$required}");
}

echo "android_max_history_ui_test: OK\n";
