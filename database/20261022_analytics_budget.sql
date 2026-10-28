-- Explicit reviewed migration. Lifetime pilot budget; no automatic reset/refill.
CREATE TABLE analytics_task_budget (
 id INTEGER PRIMARY KEY,
 approved TINYINT(1) NOT NULL DEFAULT 0,
 max_tasks INTEGER NULL,
 used_tasks INTEGER NOT NULL DEFAULT 0,
 revision BIGINT NOT NULL DEFAULT 1,
 CHECK(id=1), CHECK(max_tasks IS NULL OR max_tasks>=0), CHECK(used_tasks>=0)
) ENGINE=InnoDB;
INSERT INTO analytics_task_budget(id) VALUES(1);
CREATE TABLE analytics_task_budget_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT NOT NULL,
 data_json TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE analytics_automation_sync_state (
 id INTEGER PRIMARY KEY, publication_sequence BIGINT NOT NULL DEFAULT 0,
 current_run_id VARCHAR(36) NULL,current_sequence BIGINT NULL,after_change BIGINT NOT NULL DEFAULT 0, CHECK(id=1)
) ENGINE=InnoDB;
INSERT INTO analytics_automation_sync_state(id) VALUES(1);
