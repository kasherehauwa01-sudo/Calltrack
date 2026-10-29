-- Apply explicitly after review; never run by web requests. No roles are changed.
CREATE TABLE client_manager_bindings (
    designation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    designation VARCHAR(255) NOT NULL,
    web_user_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_manager_user FOREIGN KEY (web_user_id) REFERENCES web_users(id) ON DELETE RESTRICT,
    INDEX ix_client_manager_user (web_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_manager_binding_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    designation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    old_user_id BIGINT UNSIGNED NULL,
    new_user_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_manager_binding_event (designation_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
