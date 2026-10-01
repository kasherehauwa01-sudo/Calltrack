<?php

$repository = file_get_contents(__DIR__ . '/../app/src/main/java/com/example/calltrack/data/repository/CallRepository.kt');
$service = file_get_contents(__DIR__ . '/../app/src/main/java/com/example/calltrack/service/CallTrackingService.kt');

$checks = [
    'Личный контакт сохраняется локально до отправки на сервер' =>
        strpos($repository, "setPersonalContactLocal(phone, true)\n        syncPersonalContactToRemote(phone, true") !== false,
    'Снятие отметки также применяется локально без ожидания сети' =>
        strpos($repository, "setPersonalContactLocal(phone, false)\n        syncPersonalContactToRemote(phone, false") !== false,
    'Положительная локальная отметка имеет приоритет' =>
        strpos($repository, 'if (cachedFlag == 1) return true') !== false,
    'Сервис не показывает результат звонка личному контакту' =>
        strpos($service, 'if (!isPersonal && shouldShowPostCallPrompt(entity.type))') !== false,
    'Сервис не показывает уведомление об отсутствующем клиенте личному контакту' =>
        strpos($service, 'if (!isFinalPersonal && clientName.isBlank())') !== false,
];

foreach ($checks as $message => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "OK: уведомления после звонка личным контактам отключены\n";
