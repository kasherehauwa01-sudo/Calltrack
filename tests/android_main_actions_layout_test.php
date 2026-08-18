<?php
declare(strict_types=1);

$layout = (string)file_get_contents(dirname(__DIR__) . '/app/src/main/res/layout/activity_main.xml');
foreach (['analyticsButtonContainer', 'notificationButtonContainer', 'btnSettings'] as $id) {
    $position = strpos($layout, 'android:id="@+id/' . $id . '"');
    if ($position === false) throw new RuntimeException("Не найдена верхняя кнопка {$id}");
    $elementEnd = strpos($layout, '>', $position);
    $element = substr($layout, $position, $elementEnd - $position);
    if (!str_contains($element, 'android:elevation="12dp"')) throw new RuntimeException("Кнопка {$id} не поднята над содержимым");
}
if (!str_contains($layout, 'android:id="@+id/topActionsBarrier"') ||
    !str_contains($layout, 'app:layout_constraintTop_toBottomOf="@id/topActionsBarrier"')) {
    throw new RuntimeException('Контент экрана не ограничен нижней границей верхних кнопок');
}

echo "android_main_actions_layout_test: OK\n";
