"""Summarize one timing run; failures stay visible and out of latency percentiles."""
import argparse
import collections
import json
import math
import statistics
from pathlib import Path


def percentile(values, probability):
    if not values:
        return None
    return sorted(values)[math.ceil(probability * len(values)) - 1]


def ms(value):
    if value is None:
        return '—'
    return f'{value:,.2f}'


def report(rows):
    groups = collections.defaultdict(list)
    for row in rows:
        key = row['mode'], row['trigger'], row['packages'], row['enabled']
        if any(other['index'] == row['index'] for other in groups[key]):
            raise ValueError(f'Duplicate sample in {key}')
        groups[key].append(row)

    print('| Mode | Trigger | Packages | Capture | OK / attempted | Failures (timeouts) | Request median ms | Request p95 ms | Added request median ms | Capture median ms | Capture p95 ms | Capture API calls |')
    print('|---|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|')
    calls = collections.defaultdict(list)
    for key, attempts in sorted(groups.items()):
        mode, trigger, packages, enabled = key
        valid = [row for row in attempts if row['ok']]
        request_ms = [row['request_ms'] for row in valid]
        cap_ms = [row['capture_ms'] for row in valid]
        baseline = [row['request_ms'] for row in groups.get((mode, trigger, packages, False), []) if row['ok']]
        difference = statistics.median(request_ms) - statistics.median(baseline) if enabled and baseline and valid else None
        count = 0
        timeouts = 0
        for row in attempts:
            timeouts += int('timeout' in row.get('error', '').lower() or any(c['timeout'] for c in row.get('calls', [])))
            for call in row.get('calls', []):
                if call['capture']:
                    count += 1
                    calls[(mode, call['route'], str(call['status']))].append(call['ms'])
        print(f'| {mode} | {trigger} | {packages} | {"on" if enabled else "off"} | {len(valid)} / {len(attempts)} | '
              f'{len(attempts) - len(valid)} ({timeouts}) | {ms(statistics.median(request_ms) if valid else None)} | '
              f'{ms(percentile(request_ms, .95))} | {ms(difference)} | {ms(statistics.median(cap_ms) if valid else None)} | '
              f'{ms(percentile(cap_ms, .95))} | {count} |')

    print('\n| Capture API operation | HTTP status | Calls | Median ms | p95 ms |')
    print('|---|---:|---:|---:|---:|')
    for (mode, route, status), durations in sorted(calls.items()):
        print(f'| {mode} · {route} | {status} | {len(durations)} | {ms(statistics.median(durations))} | {ms(percentile(durations, .95))} |')
    failures = collections.Counter(row['error'] for row in rows if not row['ok'])
    for message, count in failures.items():
        print(f'\n{count} failed sample(s) · {message}')


def background_report(baseline, deferred):
    print('| Trigger | Packages | OK / attempted | Failures (timeouts) | Sync request median ms | Deferred request median ms | Deferred request p95 ms | Median ms removed | Trigger capture calls | Confirmation median ms | Confirmation p95 ms |')
    print('|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|')
    for trigger in ('processing', 'completed'):
        for packages in (1, 2):
            control = [r['request_ms'] for r in baseline if r['mode'] == 'import' and r['enabled'] and r['ok']
                       and r['trigger'] == trigger and r['packages'] == packages]
            group = [r for r in deferred if r['trigger'] == trigger and r['packages'] == packages]
            valid = [r for r in group if r['ok']]
            if not valid or not control:
                raise ValueError(f'Missing successful comparison for {trigger}, {packages} packages')
            times = [r['request_ms'] for r in valid]
            confirmations = [r['confirmation_ms'] for r in valid]
            calls = sum(c['capture'] for r in group for c in r.get('calls', []))
            timeouts = sum('timeout' in r.get('error', '').lower() or any(c['timeout'] for c in r.get('calls', []) + r.get('worker_calls', [])) for r in group)
            print(f'| {trigger} | {packages} | {len(valid)} / {len(group)} | {len(group) - len(valid)} ({timeouts}) | '
                  f'{ms(statistics.median(control))} | {ms(statistics.median(times))} | {ms(percentile(times, .95))} | '
                  f'{ms(statistics.median(control) - statistics.median(times))} | {calls} | '
                  f'{ms(statistics.median(confirmations))} | {ms(percentile(confirmations, .95))} |')
    failures = collections.Counter(r['error'] for r in deferred if not r['ok'])
    for message, count in failures.items():
        print(f'\n{count} failed sample(s) · {message}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('samples', type=Path)
    parser.add_argument('--background', type=Path)
    args = parser.parse_args()
    baseline = [json.loads(line) for line in args.samples.read_text().splitlines()]
    report(baseline)
    if args.background:
        print('\n### Data Import with background capture\n')
        background_report(baseline, [json.loads(line) for line in args.background.read_text().splitlines()])
