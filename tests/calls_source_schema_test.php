<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string)file_get_contents($root.'/database/create_calls_table.sql');
$migration = (string)file_get_contents($root.'/database/20261001_add_calls_source.sql');
$api = (string)file_get_contents($root.'/api/get_calls.php');

function callsSourceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

callsSourceAssert(str_contains($schema, 'source VARCHAR(50) NULL'), 'В новой схеме calls отсутствует совместимая колонка source');
callsSourceAssert(str_contains($migration, 'ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL'), 'Миграция source не является идемпотентной');
callsSourceAssert(!preg_match('/SELECT[^;]*\bsource\b[^;]*FROM calls/is', $api), 'Основной API не должен зависеть от необязательной колонки source');
callsSourceAssert(str_contains($api, 'SELECT id_db, call_date, call_time, phone, call_type'), 'API должен использовать явную совместимую проекцию calls');

echo "calls_source_schema_test: OK\n";
