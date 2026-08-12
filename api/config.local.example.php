<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'calltrack');
define('DB_USER', 'calltrack_user');
define('DB_PASS', 'YOUR_PASSWORD');

// JSON API проекта clients. Ответ должен содержать строки с колонками
// «Наименование» и «Телефоны» (либо name и phones).
define('CLIENTS_API_URL', 'http://127.0.0.1:8015/api/get_clients.php');
define('CLIENTS_API_TIMEOUT', 120);
