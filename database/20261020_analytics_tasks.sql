-- Explicit migration only. No runtime auto-DDL; apply after server/schema comparison.
CREATE TABLE analytics_client_bindings (
    subject_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    subject_json TEXT NOT NULL,
    client_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    status VARCHAR(20) NOT NULL,
    technical TINYINT(1) NOT NULL DEFAULT 0,
    evidence TEXT NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (status IN ('VERIFIED','REVIEW','AMBIGUOUS','NOT_FOUND')),
    CHECK (status <> 'VERIFIED' OR client_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE manager_tasks (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    client_namespace VARCHAR(40) NOT NULL DEFAULT 'clientsvr',
    client_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    assignee_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    priority VARCHAR(12) NOT NULL DEFAULT 'NORMAL',
    due_at DATETIME NULL,
    result TEXT NOT NULL,
    family VARCHAR(32) NOT NULL DEFAULT 'MANUAL',
    source_signal_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    evidence_json MEDIUMTEXT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_manager_task_assignee FOREIGN KEY (assignee_id) REFERENCES web_users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_task_signal(source_signal_key),
    INDEX ix_task_owner_status(assignee_id,status,due_at),
    CHECK(status IN ('OPEN','IN_PROGRESS','DONE','CANCELLED')),
    CHECK(priority IN ('LOW','NORMAL','HIGH','URGENT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analytics_open_task_slots (
    slot_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    task_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    CONSTRAINT fk_analytics_slot_task FOREIGN KEY (task_id) REFERENCES manager_tasks(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE manager_task_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    data_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_event_task FOREIGN KEY (task_id) REFERENCES manager_tasks(id) ON DELETE RESTRICT,
    INDEX ix_task_event(task_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analytics_inbox (
    signal_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    source VARCHAR(40) NOT NULL,
    signal_id VARCHAR(64) NOT NULL,
    signal_revision BIGINT UNSIGNED NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    subject_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision VARCHAR(40) NOT NULL,
    reasons_json TEXT NOT NULL,
    task_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_analytics_decision(decision,processed_at),
    UNIQUE KEY uq_analytics_inbox_source(source,signal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analytics_processing_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signal_revision BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(40) NOT NULL,
    reasons_json TEXT NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_analytics_processing_signal(signal_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analytics_client_binding_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    data_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_client_binding_event(subject_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analytics_sync_state (
    id INTEGER PRIMARY KEY,
    publication_sequence BIGINT NOT NULL DEFAULT 0,
    current_run_id VARCHAR(36) NULL,
    current_sequence BIGINT NULL,
    after_change BIGINT NOT NULL DEFAULT 0,
    CHECK(id=1)
) ENGINE=InnoDB;
INSERT INTO analytics_sync_state(id) VALUES(1);
CREATE TABLE analytics_delivery_rejections (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    run_id VARCHAR(36) NOT NULL,
    signal_id VARCHAR(64) NULL,
    reason_code VARCHAR(40) NOT NULL,
    event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
