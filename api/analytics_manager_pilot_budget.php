<?php
declare(strict_types=1);
require_once __DIR__.'/analytics_budget_service.php';

/** Lifetime cap by original assignee; caller must hold the task transaction. */
function assertAnalyticsManagerPilotBudget(PDO $pdo, int $manager): void {
    if (!$pdo->inTransaction()) {
        throw new AutomationBudgetBlocked('MANAGER_BUDGET_TRANSACTION_REQUIRED');
    }
    if (!in_array($manager, [3, 6], true)) {
        throw new AutomationBudgetBlocked('MANAGER_OUTSIDE_PILOT');
    }

    // Serialize with every automatic task reservation.
    $budget = analyticsBudget($pdo, true);
    if ($reason = analyticsBudgetReason($budget)) {
        throw new AutomationBudgetBlocked($reason);
    }

    // A locking read sees current committed history on MariaDB REPEATABLE READ.
    $locking = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sql = "SELECT task_id,data_json FROM manager_task_events
            WHERE event_type='CREATED' AND actor_id IS NULL
            ORDER BY id LIMIT 1001";
    if ($locking) $sql .= ' FOR UPDATE';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1000) {
        throw new AutomationBudgetBlocked('MANAGER_BUDGET_HISTORY_BOUND');
    }

    $used = 0;
    $seen = [];
    foreach ($rows as $row) {
        $event = json_decode($row['data_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event) || ($event['source'] ?? null) !== 'ANALYTICS'
            || !is_int($event['assignee_id'] ?? null)
            || isset($seen[$row['task_id']])) {
            throw new AutomationBudgetBlocked('MANAGER_BUDGET_HISTORY_INVALID');
        }
        $seen[$row['task_id']] = true;
        if ($event['assignee_id'] === $manager) $used++;
    }
    if ($used >= 5) {
        throw new AutomationBudgetBlocked('MANAGER_PILOT_BUDGET_EXHAUSTED');
    }
}
