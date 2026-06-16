-- Схема CallTrack для MySQL/MariaDB на Timeweb.
-- Создайте базу данных в панели Timeweb, выберите её и выполните этот скрипт.

CREATE TABLE IF NOT EXISTS calls (
    id_db BIGINT AUTO_INCREMENT PRIMARY KEY,
    call_date DATE,
    call_time TIME,
    phone VARCHAR(30),
    call_type VARCHAR(50),
    duration INT,
    manager VARCHAR(255),
    comment TEXT,
    tag VARCHAR(255),
    reminder DATETIME NULL,
    reminder_text TEXT,
    client VARCHAR(255),
    call_id VARCHAR(100),
    user_phone VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_call_id (call_id),
    INDEX idx_phone (phone),
    INDEX idx_user_phone (user_phone),
    INDEX idx_call_date (call_date),
    INDEX idx_manager (manager)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
