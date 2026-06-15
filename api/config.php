<?php
// Конфигурация подключения к MySQL/MariaDB на Timeweb.
// Рекомендуется переопределять значения через переменные окружения хостинга,
// а не хранить реальный пароль в репозитории.

declare(strict_types=1);

const DB_HOST = 'localhost';       // пример Timeweb: localhost или mysqlXX.timeweb.ru
const DB_NAME = 'calltrack_db';    // имя базы данных из панели Timeweb
const DB_USER = 'calltrack_user';  // пользователь базы данных
const DB_PASS = 'change_me';       // пароль пользователя базы данных
const DB_CHARSET = 'utf8mb4';

function getPdo(): PDO
{
    $host = getenv('CALLTRACK_DB_HOST') ?: DB_HOST;
    $db = getenv('CALLTRACK_DB_NAME') ?: DB_NAME;
    $user = getenv('CALLTRACK_DB_USER') ?: DB_USER;
    $pass = getenv('CALLTRACK_DB_PASS') ?: DB_PASS;
    $charset = getenv('CALLTRACK_DB_CHARSET') ?: DB_CHARSET;

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        sendJson(['status' => 'error', 'message' => 'Некорректный JSON'], 400);
    }
    return $data;
}

function valueOrNull(array $data, string $key): mixed
{
    if (!array_key_exists($key, $data)) {
        return null;
    }
    if ($data[$key] === '') {
        return null;
    }
    return $data[$key];
}

function normalizeDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    foreach (['Y-m-d', 'd.m.Y', 'd.m.y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function normalizeTime(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    foreach (['H:i:s', 'H:i'] as $format) {
        $time = DateTime::createFromFormat($format, $value);
        if ($time instanceof DateTime) {
            return $time->format('H:i:s');
        }
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('H:i:s', $timestamp);
}

function normalizeDateTime(mixed $value): ?string
{
    // MariaDB DATETIME не принимает пустую строку.
    // При отсутствии значения передаём NULL.
    if (empty($value)) {
        return null;
    }
    $timestamp = strtotime(trim((string)$value));
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function buildFilters(array $source, array &$params): string
{
    $where = [];
    foreach (['manager', 'phone', 'user_phone'] as $field) {
        if (!empty($source[$field])) {
            $where[] = "{$field} = :{$field}";
            $params[":{$field}"] = $source[$field];
        }
    }
    if (!empty($source['date_from'])) {
        $where[] = 'call_date >= :date_from';
        $params[':date_from'] = normalizeDate((string)$source['date_from']);
    }
    if (!empty($source['date_to'])) {
        $where[] = 'call_date <= :date_to';
        $params[':date_to'] = normalizeDate((string)$source['date_to']);
    }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}
