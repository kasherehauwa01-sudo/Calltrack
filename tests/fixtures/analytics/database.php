<?php
declare(strict_types=1);
/** SQLite tests run the checked-in migration with dialect-only transformations. */
function analyticsTestDatabase(): PDO {
    $dsn=getenv('ANALYTICS_TASK_TEST_DSN')?:'sqlite::memory:';
    if($dsn!=='sqlite::memory:'&&!preg_match('/^mysql:host=analytics-test-mariadb;dbname=analytics_(tasks|policy|sync|http|race)_test$/D',$dsn))throw new RuntimeException('Disposable test DB required');
    $pdo=new PDO($dsn,getenv('ANALYTICS_TASK_TEST_USER')?:null,getenv('ANALYTICS_TASK_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql){
        require_once __DIR__.'/../../../api/web_auth.php';ensureWebAuthTables($pdo);
        $stmt=$pdo->prepare('INSERT INTO web_users(id,display_name,role,is_active,login,pin_hash) VALUES(?,?,?,?,?,?)');
        foreach([[1,'Test Admin','admin',1],[2,'Test Supervisor','supervisor',1],[3,'Test Manager','manager',1],[4,'Other Manager','manager',1],[5,'Inactive','manager',0]] as $row)$stmt->execute([...$row,'fixture'.$row[0].'@example.test',password_hash('1234',PASSWORD_DEFAULT)]);
    }else{
        $pdo->exec('CREATE TABLE web_users(id INTEGER PRIMARY KEY,display_name TEXT,role TEXT,is_active INTEGER)');
        $pdo->exec("INSERT INTO web_users VALUES(1,'Test Admin','admin',1),(2,'Test Supervisor','supervisor',1),(3,'Test Manager','manager',1),(4,'Other Manager','manager',1),(5,'Inactive','manager',0)");
    }
    foreach(['20261020_manager_bindings.sql','20261020_analytics_tasks.sql','20261020_analytics_task_policy.sql'] as $file){
        $sql=file_get_contents(__DIR__.'/../../../database/'.$file);
        if(!$mysql){
            $sql=preg_replace('/BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY/','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
            $sql=preg_replace('/CHARACTER SET (ascii|utf8mb4) COLLATE (ascii_bin|utf8mb4_bin)/','',$sql);
            $sql=preg_replace('/ ON UPDATE CURRENT_TIMESTAMP/','',$sql);
            $sql=preg_replace('/ ENGINE=InnoDB[^;]*;/',';',$sql);
            $sql=preg_replace('/,\s*INDEX \w+\s*\([^)]*\)/','',$sql);
            $sql=preg_replace('/UNIQUE KEY \w+\(/','UNIQUE(',$sql);
        }
        $pdo->exec($sql);
    }

    // SQLite-only synthetic budget fixtures. Never touch production MariaDB.
    if (!$mysql) {
        $pdo->exec("CREATE TABLE analytics_task_budget (
            id INTEGER PRIMARY KEY CHECK(id=1),
            approved INTEGER NOT NULL DEFAULT 0,
            max_tasks INTEGER,
            used_tasks INTEGER NOT NULL DEFAULT 0,
            revision INTEGER NOT NULL DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE analytics_task_budget_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NOT NULL,
            revision INTEGER NOT NULL,
            data_json TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE analytics_daily_budget_policy (
            id INTEGER PRIMARY KEY CHECK(id=1),
            policy_version TEXT NOT NULL,
            timezone TEXT NOT NULL,
            max_tasks INTEGER NOT NULL CHECK(max_tasks=5)
        )");
        $pdo->exec("CREATE TABLE analytics_daily_budget (
            policy_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            used_tasks INTEGER NOT NULL DEFAULT 0 CHECK(used_tasks BETWEEN 0 AND 5),
            PRIMARY KEY(policy_id,business_date)
        )");
        $pdo->exec("CREATE TABLE analytics_daily_budget_reservations (
            reservation_key TEXT PRIMARY KEY,
            policy_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            reserved_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("INSERT INTO analytics_task_budget
            (id,approved,max_tasks,used_tasks,revision)
            VALUES (1,1,100,0,1)");
        $pdo->exec("INSERT INTO analytics_daily_budget_policy
            (id,policy_version,timezone,max_tasks)
            VALUES (1,'aviator-churn-pilot/1','Europe/Moscow',5)");
    }


    return $pdo;
}
