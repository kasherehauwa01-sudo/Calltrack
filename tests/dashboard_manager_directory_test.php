<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/api/get_calls.php');
$html=(string)file_get_contents($root.'/analizmop/index.html');

function managerDirectoryAssert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }

foreach (['function dashboardManagerDirectory(PDO $pdo, array $user): array', "role='manager' AND is_active=1", "'user_phone'=>webUserPhone((int)\$row['id'])", "'managers'=>\$managerDirectory"] as $required) {
    managerDirectoryAssert(str_contains($api,$required),"API не возвращает справочник менеджеров: {$required}");
}
foreach (["catch (Throwable \$error)", "error_log('Dashboard manager directory unavailable: '", 'return [];'] as $required) {
    managerDirectoryAssert(str_contains($api,$required),"Сбой необязательного справочника менеджеров блокирует звонки: {$required}");
}
managerDirectoryAssert(str_contains($api,"if (\$scope) {\n        return [['display_name'=>(string)\$scope['manager']"),'Manager получил чужой справочник пользователей');
managerDirectoryAssert(str_contains($api,"\$managerDirectory = [['display_name'=>(string)\$androidUser['display_name']"),'Android API не ограничивает справочник текущей учётной записью');
foreach (['let availableManagers=[]', 'payload?.managers', '[...availableManagers,...callManagers]', '[...availableManagers,...callManagers,...emailManagers]', 'await loadWebUsers();await refreshRegistryData();'] as $required) {
    managerDirectoryAssert(str_contains($html,$required),"Frontend не добавляет нового manager автоматически: {$required}");
}
managerDirectoryAssert(strpos($html,'populateManagers(allCalls);')<strpos($html,'if(!allCalls.length)'),'Менеджеры без звонков теряются при пустой статистике');

echo "dashboard_manager_directory_test: OK\n";
