<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/app/src/main/res/layout/fragment_contact_card.xml');
$source = (string)file_get_contents($root . '/app/src/main/java/com/example/calltrack/ui/contactcard/ContactCardFragment.kt');

foreach (['android:id="@+id/rowClient1c"', 'android:clickable="true"', 'android:foreground="?attr/selectableItemBackground"'] as $expected) {
    if (!str_contains($layout, $expected)) throw new RuntimeException("Строка клиента не содержит: {$expected}");
}
foreach (['binding.rowClient1c.setOnClickListener', 'showClientCard(phone, clientName)', 'Загрузка данных...', 'ClientCard(', 'Наименование клиента в 1с'] as $expected) {
    if (!str_contains($source, $expected)) throw new RuntimeException("Открытие карточки не содержит: {$expected}");
}
if (!str_contains($source, 'binding.tvClient1c.isClickable = false')) {
    throw new RuntimeException('Текст наименования продолжает перехватывать нажатие строки');
}

echo "android_contact_client_card_test: OK\n";
