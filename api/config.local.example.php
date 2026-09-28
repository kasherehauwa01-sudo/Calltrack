<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'calltrack');
define('DB_USER', 'calltrack_user');
define('DB_PASS', 'YOUR_PASSWORD');

// JSON API проекта clients. Ответ должен содержать строки с колонками
// «Наименование» и «Телефоны» (либо name и phones).
define('CLIENTS_API_URL', 'http://127.0.0.1:8015/api/get_clients.php');
define('CLIENTS_API_TIMEOUT', 120);

// Server-to-server интеграция с Sales Journal. Настоящий Bearer token
// храните только во внешнем config.local.php или переменной окружения.
define('SALES_JOURNAL_BASE_URL', ''); // https://example.ru/vr/sales или .../api/integrations/calltrack
define('CALLTRACK_INTEGRATION_TOKEN', '');
define('SALES_JOURNAL_CONNECT_TIMEOUT', 3);
define('SALES_JOURNAL_TIMEOUT', 90);
// Summary-продажи кэшируются на 10 минут; при ошибке Sales Journal можно
// безопасно показать последний успешный результат не старше суток.
define('SALES_JOURNAL_CACHE_TTL', 600);
define('SALES_JOURNAL_STALE_CACHE_TTL', 86400);
// Полная карточка продажи кэшируется отдельно, товары не загружаются заранее.
define('SALES_JOURNAL_DETAIL_CACHE_TTL', 3600);
// При нестандартном расположении PHP CLI:
// putenv('CALLTRACK_PHP_CLI=/usr/bin/php');
