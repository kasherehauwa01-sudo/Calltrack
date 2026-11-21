<?php
declare(strict_types=1);
require_once __DIR__.'/database.php';
/** Only analyticsTestDatabase's allowlisted disposable DSNs are accepted. */
function churnPermanentTestDatabase():PDO{
 $db=analyticsTestDatabase();
 if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
  $db->exec('CREATE TABLE analytics_task_budget(id INT PRIMARY KEY,approved INT,max_tasks INT,used_tasks INT,revision INT) ENGINE=InnoDB');
  $db->exec('CREATE TABLE analytics_task_budget_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_id INT,revision INT,data_json LONGTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
  $db->exec('CREATE TABLE analytics_daily_budget_policy(id INT PRIMARY KEY,policy_version VARCHAR(128),timezone VARCHAR(128),max_tasks INT) ENGINE=InnoDB');
  $db->exec('CREATE TABLE analytics_daily_budget(policy_id INT,business_date DATE,used_tasks INT,PRIMARY KEY(policy_id,business_date)) ENGINE=InnoDB');
  $db->exec('CREATE TABLE analytics_daily_budget_reservations(reservation_key VARCHAR(64) PRIMARY KEY,policy_id INT,business_date DATE,reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
  $db->exec('INSERT INTO analytics_task_budget VALUES(1,1,100,0,1)');
  $db->exec("INSERT INTO analytics_daily_budget_policy VALUES(1,'aviator-churn-pilot/1','Europe/Moscow',5)");
 }
 return $db;
}
