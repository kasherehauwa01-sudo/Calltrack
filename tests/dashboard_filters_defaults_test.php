<?php
declare(strict_types=1);

$html=(string)file_get_contents(dirname(__DIR__).'/analizmop/index.html');
function filterAssert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }

$dashboardPeriod=(string)preg_replace('/<div class="date-range">[\s\S]*$/','',(string)explode('<div class="period-shell" id="periodShell">',$html,2)[1]);
filterAssert(strpos($dashboardPeriod,'data-period="year"')<strpos($dashboardPeriod,'data-period="all"'),'Период «Все» находится не после «Год»');
$clientPeriod=(string)explode('</div>',(string)explode('<div class="period-shell" id="clientPeriodShell">',$html,2)[1],2)[0];
foreach (['data-period="quarter" type="button">Квартал','data-period="year" type="button">Год'] as $period)filterAssert(str_contains($clientPeriod,$period),"В истории клиентов отсутствует период: {$period}");
filterAssert(str_contains($html,"const activeCallTypes=new Set(['Входящий','Исходящий','MAX Входящие','MAX исходящие'])"),'По умолчанию выбраны неверные типы звонков');
foreach (['MAX Входящие','MAX исходящие','MAX пропущенные'] as $maxType) filterAssert(str_contains($html,$maxType),"В фильтрах или диаграммах отсутствует тип: {$maxType}");
filterAssert(str_contains($html,'.call-segment.max-call{background:#9132d5'),'Плашки MAX-звонков имеют неверный цвет');
foreach (['manager-toggle-btn','Снять все','Добавить все','activeManagers.clear()','updateManagerToggleButton()'] as $toggle)filterAssert(str_contains($html,$toggle),"Не реализовано массовое переключение менеджеров: {$toggle}");
filterAssert(strpos($html,'${tags}<button class="manager-toggle-btn"')!==false,'Кнопка выбора менеджеров находится не после тегов');

echo "dashboard_filters_defaults_test: OK\n";
