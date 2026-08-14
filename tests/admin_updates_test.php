<?php
declare(strict_types=1);

define('CALLTRACK_ADMIN_UPDATES_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__) . '/api/admin_updates.php';

$current = ['version_name'=>'1.0.13', 'version_code'=>13];
$automatic = ['version_name'=>'1.0.14', 'version_code'=>21];
$changed = resolveEditedUpdateVersion($current, $automatic, '1.1.0');
if ($changed !== ['version_name'=>'1.1.0', 'version_code'=>21]) {
    throw new RuntimeException('При изменении версии VersionCode не увеличен автоматически');
}
$unchanged = resolveEditedUpdateVersion($current, $automatic, '1.0.13');
if ($unchanged !== $current) throw new RuntimeException('VersionCode изменился без изменения версии');
try {
    resolveEditedUpdateVersion($current, $automatic, 'version-14');
    throw new RuntimeException('Некорректный формат версии был принят');
} catch (InvalidArgumentException $expected) {
}

echo "admin_updates_test: OK\n";
