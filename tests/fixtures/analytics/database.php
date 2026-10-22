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
    foreach(['20261020_manager_bindings.sql','20261020_analytics_tasks.sql','20261020_analytics_task_policy.sql','20261022_analytics_budget.sql'] as $file){
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
    return $pdo;
}
