-- Безопасно расширяет допустимые web-роли, не меняя существующих пользователей.
ALTER TABLE web_users
    MODIFY role ENUM('admin','supervisor','manager') NOT NULL;
