<?php
declare(strict_types=1);

$html=(string)file_get_contents(dirname(__DIR__).'/analizmop/index.html');
foreach ([
    '<button class="detail-pill active" data-group="month">По месяцам</button>',
    "let activeGroupBy='month'",
    "all:'month'",
] as $required) {
    if (!str_contains($html,$required)) throw new RuntimeException("Детализация по месяцам не установлена по умолчанию: {$required}");
}
if (str_contains($html,'<button class="detail-pill active" data-group="day">По дням</button>')) {
    throw new RuntimeException('Кнопка «По дням» ошибочно осталась активной по умолчанию');
}

echo "dashboard_default_grouping_test: OK\n";
