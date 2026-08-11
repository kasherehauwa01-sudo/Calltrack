<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'calltrack');
define('DB_USER', 'calltrack_user');
define('DB_PASS', 'YOUR_PASSWORD');

// JSON API проекта clients. Ответ должен содержать строки с колонками
// «Наименование» и «Телефоны» (либо name и phones).
define('CLIENTS_API_URL', 'https://kvasmix.ru/vr/clients/api/get_clients.php');
define('CLIENTS_CARD_API_URL', 'https://kvasmix.ru/vr/clients/api/client_card.php');
define('CLIENTS_API_PORT', 0); // 0 — взять порт из URL; укажите число только для нестандартного порта.
define('CLIENTS_API_TOKEN', ''); // Не коммитьте реальный токен.
define('CLIENTS_API_CONNECT_TIMEOUT', 3);
define('CLIENTS_API_TIMEOUT', 8);
define('CLIENTS_API_RESOLVE_LOCAL', true); // kvasmix.ru:443 подключается к 127.0.0.1 с корректным TLS SNI.
