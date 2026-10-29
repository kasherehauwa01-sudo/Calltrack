-- Business settings are deliberately unapproved and unset. Explicit migration only.
CREATE TABLE analytics_task_policy (
    id INTEGER PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    min_confidence DECIMAL(5,4) NULL,
    approved TINYINT(1) NOT NULL DEFAULT 0,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    CHECK(id=1),
    CHECK(min_confidence IS NULL OR min_confidence BETWEEN 0 AND 1)
) ENGINE=InnoDB;
CREATE TABLE analytics_task_type_policy (
    signal_type VARCHAR(32) PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    priority VARCHAR(12) NULL,
    due_days INTEGER NULL,
    CHECK(signal_type IN ('PURCHASE_OVERDUE','CHURN_RISK')),
    CHECK(priority IS NULL OR priority IN ('LOW','NORMAL','HIGH','URGENT')),
    CHECK(due_days IS NULL OR due_days BETWEEN 0 AND 365)
) ENGINE=InnoDB;
CREATE TABLE analytics_task_policy_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    policy_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO analytics_task_policy(id) VALUES(1);
INSERT INTO analytics_task_type_policy(signal_type) VALUES('PURCHASE_OVERDUE'),('CHURN_RISK');
