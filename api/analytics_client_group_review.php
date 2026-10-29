<?php
declare(strict_types=1);

require_once __DIR__ . '/client_catalog.php';

/**
 * Advisory matching only.
 * Never verifies identities, selects a canonical client, or creates tasks.
 */
function analyticsClientGroupReview(
    array $records,
    array $managers,
    string $buyerName,
    array $buyerPhones,
    array $pilotManagerIds = [3, 6]
): array {
    $normalizeName = static function (string $value): string {
        return mb_strtolower(trim(preg_replace(
            '/[\x{0009}-\x{000D} \x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u',
            ' ',
            $value
        ) ?? ''));
    };

    $name = $normalizeName($buyerName);
    $phones = [];

    foreach ($buyerPhones as $phone) {
        $normalized = normalizeClientPhone((string)$phone);
        if ($normalized !== '') {
            $phones[$normalized] = true;
        }
    }

    if ($name === '' || !$phones) {
        return ['status' => 'REVIEW', 'reason' => 'MISSING_IDENTITY_DATA'];
    }

    $index = clientCatalogOwnerIndex($managers);
    $matched = [];
    $owners = [];
    $unresolved = false;
    $conflict = false;

    foreach ($records as $record) {
        if ($normalizeName((string)($record['name'] ?? '')) !== $name) {
            continue;
        }

        // Cards without manager are technical for assignment purposes.
        if (trim((string)($record['manager'] ?? '')) === '') {
            continue;
        }

        if (!empty($record['manager_conflict'])) {
            $conflict = true;
            continue;
        }

        $owner = clientCatalogOwner($record, $managers, $index);

        if (!$owner) {
            $unresolved = true;
            continue;
        }

        $managerId = (int)$owner['id'];
        $owners[$managerId] = true;

        $cardPhones = [];
        foreach ($record['phones'] ?? [] as $phone) {
            $normalized = normalizeClientPhone((string)$phone);
            if ($normalized !== '') {
                $cardPhones[$normalized] = true;
            }
        }

        if (array_intersect_key($phones, $cardPhones)) {
            $matched[(string)$record['id']] = true;
        }
    }

    if ($conflict || count($owners) > 1) {
        return ['status' => 'REVIEW', 'reason' => 'MANAGER_CONFLICT'];
    }

    if ($unresolved) {
        return ['status' => 'REVIEW', 'reason' => 'UNRESOLVED_MANAGER'];
    }

    if (!$matched || count($owners) !== 1) {
        return ['status' => 'REVIEW', 'reason' => 'NO_VERIFIED_CANDIDATE'];
    }

    $managerId = (int)array_key_first($owners);

    if (!in_array($managerId, $pilotManagerIds, true)) {
        return ['status' => 'OUT_OF_PILOT'];
    }

    return [
        'status' => 'CANDIDATE',
        'manager_id' => $managerId,
        'client_ids' => array_keys($matched),
        'requires_manual_verification' => true
    ];
}
