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

$rawCache = tempnam(sys_get_temp_dir(), 'calltrack_clients_test_');
file_put_contents($rawCache, json_encode(['rows'=>[['name'=>'Тест']], 'source_total'=>1]));
expectSame(
    ['rows'=>[['name'=>'Тест']], 'source_total'=>1],
    readClientsRawCache($rawCache),
    'Свежий сырой ответ Clients должен читаться из кэша без повторного HTTP-запроса'
);
expectSame(
    'https://kvasmix.ru/vr/clients/api/client_card.php?phone=9991234567',
    clientsCardApiUrl('9991234567'),
    'Карточка должна запрашиваться через быстрый endpoint поиска по телефону'
);
expectSame(
    "Accept: application/json\r\nUser-Agent: CallTrack/test\r\nConnection: close\r\n",
    clientsRequestHeaders('https://kvasmix.ru/vr/clients/api/client_card.php', 'CallTrack/test'),
    'Публичный URL должен сохранять корректный Host для TLS SNI'
);
expectSame(443, clientsApiPort('https://kvasmix.ru/vr/clients/api/get_clients.php'), 'HTTPS должен использовать порт 443');
expectSame(80, clientsApiPort('http://127.0.0.1/vr/clients/api/get_clients.php'), 'HTTP loopback должен использовать порт 80');
expectSame(8443, clientsApiPort('https://kvasmix.ru/vr/clients/api/get_clients.php', 8443), 'Явно заданный порт должен сохраняться');
expectSame(
    ['rows'=>[['name'=>'Тест']], 'source_total'=>1],
    readClientsStaleRawCache($rawCache),
    'При параллельном обновлении допустимо сразу вернуть ранее сохранённый справочник'
);
unlink($rawCache);

expectSame([
    'found'=>false,
    'phone'=>'12345',
    'normalized'=>'',
    'matches'=>[],
    'matches_count'=>0,
    'reason'=>'После удаления форматирования номер должен содержать 10 цифр.',
], testClientPhone('12345'), 'Некорректный номер не должен запускать внешний API-запрос');

echo "client_directory_test: OK\n";
