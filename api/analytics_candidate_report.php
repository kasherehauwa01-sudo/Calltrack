<?php
declare(strict_types=1);

/**
 * Read-only, advisory candidate lookup.
 * Must be called only after admin authorization.
 */
function analyticsCandidateReportLookup(array $event): ?array
{
    if (($event['signal_type'] ?? null) !== 'CHURN_RISK'
        || ($event['subject']['kind'] ?? null) !== 'normalized_name') {
        return null;
    }

    $path = '/var/lib/calltrack-review/candidates.json';
    static $report = null;
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;

        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('Candidate report unreadable');
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (($data['schema_version'] ?? null) !== 1
                || ($data['status'] ?? null) !== 'ADVISORY_ONLY'
                || ($data['run_id'] ?? null) !== 'd1d57699-27ba-4d1e-a292-aac33d5e26e1'
                || count($data['candidates'] ?? []) !== 281) {
                throw new RuntimeException('Candidate report invalid');
            }

            $report = $data;
        } catch (Throwable $e) {
            error_log('analytics_candidate_report: '.$e->getMessage());
            return null;
        }
    }

    if ($report === null) {
        return null;
    }

    $runId = $event['run']['id'] ?? $event['run']['run_id'] ?? null;
    if ($runId !== $report['run_id']) {
        return null;
    }

    $subjectKey = $event['subject']['key'] ?? null;
    if (!is_string($subjectKey) || $subjectKey === '') {
        return null;
    }

    $candidate = $report['candidates'][hash('sha256', $subjectKey)] ?? null;
    if (!is_array($candidate)
        || ($candidate['requires_manual_verification'] ?? null) !== true
        || !in_array($candidate['manager_id'] ?? null, [3, 6], true)
        || !is_array($candidate['client_ids'] ?? null)) {
        return null;
    }

    return [
        'status' => 'CANDIDATE',
        'manager_id' => (int)$candidate['manager_id'],
        'manager_name' => (int)$candidate['manager_id'] === 3
            ? 'Пашута' : 'Селянкина',
        'client_ids' => array_values($candidate['client_ids']),
        'requires_manual_verification' => true,
        'report_generated_at' => $report['generated_at'] ?? null
    ];
}
