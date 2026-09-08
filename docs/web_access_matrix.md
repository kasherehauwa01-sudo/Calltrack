# Web-доступ Calltrack

| Категория | Endpoint | Доступ |
|---|---|---|
| A | `admin_updates.php`, `admin_install_update.php`, `admin_clients_cache.php`, `get_users.php`, `user_command.php`, `delete_calls.php`, `delete_personal_contacts.php`, `web_users.php` | Только `admin` |
| B | `get_calls.php`, `dashboard.php`, `update_call.php`, чтение `admin_email.php` | `admin` — все данные; `manager` — только `manager_user_phone`/связанный менеджер из сессии |
| C | `web_auth_api.php`, `product_by_ean.php` | Авторизация/текущая web-сессия |
| D | `add_call.php`, `get_history.php`, `get_personal_contacts.php?user_phone=…`, `personal_contact.php`, `user_report.php`, `user_command_done.php`, `update.php`, `client_directory.php`, `test_clients.php` | Технические endpoint Android; web-сессия к ним не добавлялась |

Web-роль может иметь только значение `admin` или `manager`. Для `manager` обязательна связь со стабильным `user_phone` существующего менеджера Android/Calltrack.
