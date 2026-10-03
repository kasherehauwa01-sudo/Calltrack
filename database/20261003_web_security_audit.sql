-- Журнал web-аутентификации и административных действий без хранения секретов.
CREATE TABLE IF NOT EXISTS web_security_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    web_user_id BIGINT UNSIGNED NULL,
    actor_login VARCHAR(254) NULL,
    event_type VARCHAR(80) NOT NULL,
    outcome VARCHAR(20) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    metadata_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_web_security_audit_created (created_at),
    INDEX idx_web_security_audit_user (web_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
