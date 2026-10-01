-- Применяется вручную. Откат: удалить перечисленные колонки и индекс.
ALTER TABLE calls
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'phone' AFTER user_phone,
    ADD COLUMN source_event_id VARCHAR(190) NULL AFTER source,
    ADD COLUMN contact_name VARCHAR(255) NULL AFTER source_event_id,
    ADD COLUMN direction VARCHAR(20) NULL AFTER contact_name,
    ADD COLUMN status VARCHAR(20) NULL AFTER direction,
    ADD COLUMN started_at DATETIME NULL AFTER status,
    ADD COLUMN answered_at DATETIME NULL AFTER started_at,
    ADD COLUMN ended_at DATETIME NULL AFTER answered_at,
    ADD COLUMN ringing_duration_seconds INT NULL AFTER ended_at,
    ADD COLUMN is_video TINYINT(1) NOT NULL DEFAULT 0 AFTER ringing_duration_seconds,
    ADD COLUMN contact_resolution_status VARCHAR(50) NULL AFTER is_video,
    ADD UNIQUE KEY uk_source_event (source, source_event_id);
