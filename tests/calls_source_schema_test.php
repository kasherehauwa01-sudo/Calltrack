<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string)file_get_contents($root.'/database/create_calls_table.sql');
$migration = (string)file_get_contents($root.'/database/20261001_add_calls_source.sql');
$maxMigration = (string)file_get_contents($root.'/database/20261002_add_max_call_fields.sql');
$api = (string)file_get_contents($root.'/api/get_calls.php');

function callsSourceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

callsSourceAssert(str_contains($schema, "source VARCHAR(20) NOT NULL DEFAULT 'phone'"), 'В новой схеме calls отсутствует колонка source');
callsSourceAssert(str_contains($migration, 'ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL'), 'Миграция source не является идемпотентной');
callsSourceAssert(str_contains($maxMigration, "MODIFY COLUMN source VARCHAR(20) NOT NULL DEFAULT 'phone'"), 'MAX-миграция не завершает настройку source');
callsSourceAssert(str_contains($api, 'source, source_event_id, contact_name'), 'Основной API не возвращает источник MAX-звонка');
callsSourceAssert(str_contains($api, 'SELECT id_db, call_date, call_time, phone, call_type'), 'API должен использовать явную совместимую проекцию calls');

echo "calls_source_schema_test: OK\n";
