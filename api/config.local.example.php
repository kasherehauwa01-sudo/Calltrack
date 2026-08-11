<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'calltrack');
define('DB_USER', 'calltrack_user');
define('DB_PASS', 'YOUR_PASSWORD');

// JSON API проекта clients. Ответ должен содержать строки с колонками
// «Наименование» и «Телефоны» (либо name и phones).
define('CLIENTS_API_URL', 'https://kvasmix.ru/vr/clients/api/get_clients.php');
define('CLIENTS_CARD_API_URL', 'https://kvasmix.ru/vr/clients/api/client_card.php');
