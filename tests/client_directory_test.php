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
expectSame(
    ['9991234567', '4951112233'],
    splitClientPhones(['рабочий: +7 (999) 123-45-67', 'дополнительный: 4951112233']),
    'Номера из массива и текста должны разбираться без потери совпадений'
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

$cards = findClientCardsInRows([
    ['Наименование'=>'ООО Ромашка', 'Телефоны'=>['+7 999 123-45-67'], 'ИНН'=>'1234567890', 'Комментарий'=>'', 'Активен'=>true],
], '9991234567');
expectSame([
    ['name'=>'ООО Ромашка', 'fields'=>[
        'Наименование'=>'ООО Ромашка',
        'Телефоны'=>'+7 999 123-45-67',
        'ИНН'=>'1234567890',
        'Активен'=>'Да',
    ]],
], $cards, 'Карточка должна содержать все заполненные поля клиента');

expectSame([
    'found'=>false,
    'phone'=>'12345',
    'normalized'=>'',
    'matches'=>[],
    'matches_count'=>0,
    'reason'=>'После удаления форматирования номер должен содержать 10 цифр.',
], testClientPhone('12345'), 'Некорректный номер не должен запускать внешний API-запрос');

echo "client_directory_test: OK\n";
