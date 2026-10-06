-- Расширение calls для идемпотентной синхронизации звонков MAX.
-- Существующие телефонные звонки получают source='phone'; остальные поля nullable.
ALTER TABLE calls
    ADD COLUMN IF NOT EXISTS source_event_id VARCHAR(190) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS contact_name VARCHAR(255) NULL AFTER source_event_id,
    ADD COLUMN IF NOT EXISTS direction VARCHAR(20) NULL AFTER contact_name,
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL AFTER direction,
    ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS answered_at DATETIME NULL AFTER started_at,
    ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL AFTER answered_at,
    ADD COLUMN IF NOT EXISTS ringing_duration_seconds INT NULL AFTER ended_at,
    ADD COLUMN IF NOT EXISTS is_video TINYINT(1) NOT NULL DEFAULT 0 AFTER ringing_duration_seconds,
    ADD COLUMN IF NOT EXISTS contact_resolution_status VARCHAR(50) NULL AFTER is_video;

UPDATE calls SET source='phone' WHERE source IS NULL OR source='';
ALTER TABLE calls MODIFY COLUMN source VARCHAR(20) NOT NULL DEFAULT 'phone';
CREATE UNIQUE INDEX IF NOT EXISTS uk_source_event ON calls(source, source_event_id);
