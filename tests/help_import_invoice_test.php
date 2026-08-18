<?php

declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../analizmop/index.html');
if ($html === false) {
    throw new RuntimeException('Не удалось прочитать страницу аналитики.');
}

$requiredFragments = [
    'data-tab="help"',
    'id="helpView"',
    '<summary>Импорт в первичный счет</summary>',
    'Инструкция по экспорту XLS в первичный счет',
    'Для экспорта в первичный счет',
    '7-Zip → Распаковать',
    'Сервис → Дополнительные возможности → Импорт счетов',
    'На основании созданного первичного счета оформите списание товара.',
];

foreach ($requiredFragments as $fragment) {
    if (!str_contains($html, $fragment)) {
        throw new RuntimeException("На странице помощи отсутствует обязательный фрагмент: {$fragment}");
    }
}

if (!str_contains($html, "const isHelp=tab==='help';")
    || !str_contains($html, "els.helpView.classList.toggle('active',isHelp);")) {
    throw new RuntimeException('Вкладка «Помощь» не подключена к переключателю разделов.');
}

echo "help_import_invoice_test: OK\n";
