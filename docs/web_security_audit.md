# Аудит web-безопасности Calltrack

## Состояние до исправления

### Critical

- В статическом HTML находился общий пароль административной панели. Любой пользователь мог прочитать его из исходного кода страницы.
- `delete_call.php` удалял звонок без web-сессии и без проверки роли.

### High

- `admin_clients_cache.php` и `admin_install_update.php` принимали общий секрет в `X-Calltrack-Admin-Password`; frontend отправлял этот секрет при каждом запросе.
- Изменяющие cookie-session endpoints не проверяли CSRF-токен.
- Ручная синхронизация Email запускалась через GET.
- У web-сессии отсутствовали idle timeout и absolute lifetime.
- IMAP-пароли используют AES-256-CBC без MAC. При отсутствии application key новые значения сохранялись как Base64, то есть без шифрования.
- Несколько совместимых с прежними Android-сборками endpoint используют `optionalAndroidUser()` и принимают переданный `user_phone`, если Bearer отсутствует. Это касается `add_call.php`, `get_history.php`, `user_report.php` и `user_command_done.php`.

### Medium

- Не выполнялся `password_needs_rehash()` для PIN.
- Старые строки `web_login_attempts` не очищались.
- Logout выполнялся изменяющим GET-запросом и удалял cookie без полного набора исходных атрибутов.
- Отсутствовал централизованный security audit log.
- API не выставляли единый набор защитных HTTP-заголовков.

### Low / остаточный риск

- Разрешены PIN длиной от 4 цифр. Rate limit снижает риск онлайн-перебора, но короткий PIN остаётся слабее полноценного пароля.
- Полная CSP для статического web-интерфейса потребует выноса inline JavaScript и CSS. В рамках этого изменения добавлен только безопасный `frame-ancestors 'none'`.

## Матрица доступа после исправления

### Admin session

- `web_users.php` — просмотр и управление web-пользователями.
- `admin_updates.php`, `admin_install_update.php` — управление APK и очередью обновления.
- `admin_clients_cache.php` — статус и запуск обновления ClientsVR cache.
- `get_users.php`, `user_command.php` — Android-телеметрия и административные команды.
- `delete_call.php`, `delete_calls.php`, `delete_personal_contacts.php` — удаление данных.
- `admin_email.php?action=settings|sync|test` и POST-настройки — конфигурация и синхронизация Email.
- Полный административный режим `get_personal_contacts.php`.

Для изменяющих запросов одновременно обязательны admin role и корректный `X-CSRF-Token`.

### Supervisor session

- Dashboard, `get_calls.php`, `dashboard.php` — данные всех менеджеров.
- `client_sales.php`, `sale_detail.php`, реестр Email и детали писем в разрешённой области.
- Нет доступа к перечисленным admin endpoints: backend возвращает 403.

### Manager session

- Dashboard, история клиентов, Email и Sales Journal только в действующем manager scope.
- Изменение разрешённых комментариев, тегов и напоминаний через `update_call.php` только для собственного `user_phone`.
- Нет доступа к admin endpoints: backend возвращает 403.

### Android Bearer

- `android_auth_api.php` выдаёт случайный token, а сервер хранит только SHA-256 hash.
- `currentAndroidUser()` проверяет срок, отзыв token и `is_active` пользователя.
- Logout помечает конкретный token как revoked.
- Cookie session и CSRF к Android Bearer-запросам не применяются.

## Email secrets

- `admin_email.php` не возвращает `password_encrypted` или расшифрованный пароль.
- Ключ читается из `CALLTRACK_SECRET_KEY`/`APP_SECRET_KEY`, а не из таблицы Email.
- Текущий CBC-формат не является authenticated encryption; legacy Base64 fallback также остаётся для чтения существующих данных.
- Рекомендуется отдельная миграция на AES-256-GCM или libsodium secretbox с версионированным ciphertext. До миграции следует запретить создание новых mailbox без настроенного внешнего ключа, предварительно проверив production-конфигурацию.

## Следующий этап

1. Перевести оставшиеся legacy Android endpoints с optional bearer на обязательный Bearer после подтверждения минимальной поддерживаемой версии приложения.
2. Защитить `client_directory.php` и `test_clients.php` общей web-or-Android авторизацией без нарушения Android ClientsVR lookup.
3. Мигрировать IMAP ciphertext на authenticated encryption и удалить plaintext Base64 fallback.
4. Расширить audit log событиями Email, APK, Clients cache и командами устройства; в metadata не писать секреты и содержимое писем.
5. Вынести inline JS/CSS и включить полную CSP с nonce/hash.
6. Добавить периодическую очистку старых audit-записей согласно согласованному retention policy.
