#!/usr/bin/env python3
"""Fail the build when Plugin Check reports ERRORs.

Reads `wp plugin check <slug> --format=json` from stdin. The output is a stream of `FILE: <path>` lines
each followed by a JSON array of findings: {line, column, type, code, message}. Warnings are listed
but never fail the build (custom-table queries are expected to produce some).
"""
import collections
import json
import sys


def main():
    findings, current = [], None
    for raw in sys.stdin:
        line = raw.rstrip('\n')
        if line.startswith('FILE: '):
            current = line[6:].strip()
        elif line.startswith('['):
            for item in json.loads(line):
                item['file'] = current
                findings.append(item)

    counts = collections.Counter(item['type'] for item in findings)
    print('Plugin Check: %d findings (%s)' % (len(findings), ', '.join('%d %s' % (n, t) for t, n in sorted(counts.items())) or 'none'))
    for item in findings:
        if item['type'] == 'ERROR':
            print('ERROR %s:%s %s %s' % (item['file'], item.get('line'), item['code'], item['message'][:160]))
    sys.exit(1 if counts.get('ERROR') else 0)


if __name__ == '__main__':
    main()
