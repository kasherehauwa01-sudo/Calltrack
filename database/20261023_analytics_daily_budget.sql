-- Apply only after 20261022_analytics_budget.sql. Does not enable automation.
CREATE TABLE analytics_daily_budget_policy (
 id TINYINT PRIMARY KEY,
 policy_version VARCHAR(64) NOT NULL,
 timezone VARCHAR(64) NOT NULL,
 max_tasks INT NOT NULL,
 CONSTRAINT ck_daily_policy_id CHECK (id=1),
 CONSTRAINT ck_daily_policy_max CHECK (max_tasks=5)
) ENGINE=InnoDB;
INSERT INTO analytics_daily_budget_policy VALUES(1,'aviator-churn-pilot/1','Europe/Moscow',5);
CREATE TABLE analytics_daily_budget (
 policy_id TINYINT NOT NULL,
 business_date DATE NOT NULL,
 used_tasks INT NOT NULL DEFAULT 0,
 PRIMARY KEY(policy_id,business_date),
 CONSTRAINT fk_daily_policy FOREIGN KEY(policy_id) REFERENCES analytics_daily_budget_policy(id),
 CONSTRAINT ck_daily_used CHECK (used_tasks BETWEEN 0 AND 5)
) ENGINE=InnoDB;
CREATE TABLE analytics_daily_budget_reservations (
 reservation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 policy_id TINYINT NOT NULL,
 business_date DATE NOT NULL,
 reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX ix_daily_reservation_bucket(policy_id,business_date),
 CONSTRAINT fk_daily_reservation_bucket FOREIGN KEY(policy_id,business_date)
 REFERENCES analytics_daily_budget(policy_id,business_date) ON DELETE RESTRICT
) ENGINE=InnoDB;
