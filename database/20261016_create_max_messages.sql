-- MAX personal message metadata only. occurred_at is UTC; no message body column.
CREATE TABLE IF NOT EXISTS max_messages (
    id_db BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_event_id VARCHAR(190) NOT NULL,
    direction VARCHAR(20) NOT NULL,
    contact_name VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(30) NULL,
    max_user_id VARCHAR(190) NULL,
    shortcut_id VARCHAR(190) NULL,
    occurred_at DATETIME NOT NULL,
    manager VARCHAR(255) NULL,
    user_phone VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_max_messages_source_event (source_event_id),
    KEY idx_max_messages_user_time (user_phone, occurred_at),
    KEY idx_max_messages_manager_time (manager, occurred_at),
    KEY idx_max_messages_phone_time (phone, occurred_at),
    KEY idx_max_messages_user_identity (max_user_id, occurred_at),
    KEY idx_max_messages_shortcut_time (shortcut_id, occurred_at),
    KEY idx_max_messages_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
