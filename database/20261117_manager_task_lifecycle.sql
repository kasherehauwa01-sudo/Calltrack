-- Retain task history and suppress recreation of deleted analytical tasks.
ALTER TABLE manager_tasks ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

-- Keep the original unique slot for creation and track manually reopened tasks in the same group.
ALTER TABLE analytics_open_task_slots ADD COLUMN reopened_slot_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
CREATE INDEX ix_analytics_reopened_slot ON analytics_open_task_slots(reopened_slot_key);
