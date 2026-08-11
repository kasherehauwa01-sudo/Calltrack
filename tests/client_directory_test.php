<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/client_directory.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

expectSame('9991234567', normalizeClientPhone('+7 (999) 123-45-67'), 'Номер с +7 должен нормализоваться');
expectSame('9991234567', normalizeClientPhone('8 999 123 45 67'), 'Номер с 8 должен нормализоваться');
expectSame('', normalizeClientPhone('12345'), 'Короткое значение должно отклоняться');
expectSame(
    ['9991234567', '4951112233'],
    splitClientPhones("+7 (999) 123-45-67; 8 (495) 111-22-33"),
    'Несколько номеров в строке должны разбираться'
);

$rows = [
    ['name'=>'ООО Ромашка', 'phones'=>['+7 (999) 123-45-67', '8 999 123 45 67']],
    ['Наименование'=>'ИП Иванов', 'Телефоны'=>'+7 (495) 111-22-33'],
    ['name'=>'', 'phones'=>['+7 (900) 000-00-00']],
    ['name'=>'Без телефона', 'phones'=>[]],
];
$clients = normalizeClientsPayload($rows);

expectSame([
    ['name'=>'ООО Ромашка', 'phones'=>['9991234567']],
    ['name'=>'ИП Иванов', 'phones'=>['4951112233']],
], $clients, 'Массив phones и русские названия полей должны поддерживаться');

expectSame([
    ['phone'=>'+79991234567', 'name'=>'ООО Ромашка'],
], findClientsByPhone($clients, '9991234567'), 'Поиск должен вернуть канонический номер и наименование');

echo "client_directory_test: OK\n";
