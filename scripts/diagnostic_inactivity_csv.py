#!/usr/bin/env python3
"""Replay a frozen diagnostic CSV; never recalculate or publish analytics.

Input/output contain customer identities. Keep both outside Git/web roots.
No database, network, configuration or engine imports are used here.
"""
import argparse
import csv
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path


def prepare(path: Path, expected_sha256: str, as_of: str) -> dict:
    raw = path.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    if digest != expected_sha256.lower():
        raise ValueError('CSV SHA256 mismatch')
    datetime.strptime(as_of, '%Y-%m-%d')
    with path.open(encoding='utf-8-sig', newline='') as stream:
        rows = list(csv.DictReader(stream, delimiter=';'))
    events, seen, stages = [], set(), {}
    packaged_at = datetime.now(timezone.utc).isoformat()
    scope = {'policy': 'diagnostic/preselected-price-type-export', 'department': 'Авиаторов',
             'price_types': ['Оптовые', 'Корпоративные'], 'buyer_types': [],
             'buyer_type_membership': 'NOT_EXPORTED'}
    for row in rows:
        key = row['normalized_client_name']
        if not key or key in seen:
            raise ValueError('Missing/duplicate diagnostic subject')
        seen.add(key)
        kind = row['signal_type']
        if kind not in ('PURCHASE_OVERDUE', 'CHURN_RISK'):
            raise ValueError('Unexpected signal type')
        if row['diagnostic_as_of'] != as_of or row['automation_allowed'] != 'False' or row['published'] != 'False' or row['accepted_data_through']:
            raise ValueError('Input is not an unpublished diagnostic snapshot')
        stages[kind] = stages.get(kind, 0) + 1
        metrics = {name: row[column] for name, column in (
            ('median_interval', 'median_interval'), ('mean_interval', 'mean_interval'),
            ('q75', 'Q75'), ('mad', 'MAD'), ('days_without_purchase', 'days_since_purchase'),
            ('overdue_threshold', 'overdue_threshold'), ('churn_threshold', 'churn_threshold'),
            ('deviation_ratio', 'overdue_ratio'), ('distinct_purchase_days', 'purchase_days_365'),
            ('history_span_days', 'history_span_365'))}
        events.append({'source': 'sales-journal', 'signal_id': 'diag-' + hashlib.sha256((digest + '\0' + key).encode()).hexdigest()[:48],
            'revision': 1, 'status': 'ACTIVE', 'family': 'INACTIVITY', 'signal_type': kind,
            'subject': {'kind': 'normalized_name', 'version': 1, 'key': key},
            'score': row['score'], 'confidence': row['confidence'], 'last_purchase': row['last_purchase_date'],
            'as_of': as_of, 'calculated_at': packaged_at,
            'calculated_at_provenance': 'DIAGNOSTIC_REPLAY_PACKAGED_AT_NOT_ENGINE_TIMESTAMP',
            'analytical_eligible': True, 'generator_version': 'inactivity/1', 'metrics': metrics,
            'mode': 'SHADOW', 'automation_allowed': False,
            'run': {'id': 'diag-' + digest[:24], 'status': 'DIAGNOSTIC', 'as_of': as_of,
                'source_revision': None, 'scope': scope, 'automation_allowed': False,
                'source_decision': {'coverage_status': 'UNKNOWN', 'business_freshness': 'UNKNOWN',
                    'accepted_data_through': None, 'effective_as_of': None, 'source_revision': None}}})
    return {'diagnostic_only': True, 'csv_sha256': digest, 'as_of': as_of,
            'generated_at': packaged_at, 'counts': stages, 'events': events}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--csv', type=Path, required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--as-of', required=True)
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    data = prepare(args.csv, args.sha256, args.as_of)
    # Never replace an existing file or place private diagnostic data inside this repository.
    repo = Path(__file__).resolve().parents[1]
    if args.out.resolve().is_relative_to(repo):
        raise ValueError('Diagnostic output must be outside the repository')
    with open(args.out, 'x', encoding='utf-8', opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
        json.dump(data, stream, ensure_ascii=False)
    print(json.dumps({'events': len(data['events']), 'stages': data['counts'], 'csv_sha256': data['csv_sha256']}))


if __name__ == '__main__':
    main()
