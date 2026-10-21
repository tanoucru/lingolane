#!/usr/bin/env python3
"""Compile every lingolane/languages/*.po to a .mo next to it (no gettext tools needed)."""
import ast
import glob
import os
import struct
import sys


def parse_po(path):
    entries, cur, field = [], {}, None

    def flush():
        nonlocal cur
        if cur.get('msgid') is not None and not cur.get('fuzzy'):
            entries.append(cur)
        cur = {}

    with open(path, encoding='utf-8') as fh:
        for raw in fh:
            line = raw.strip()
            if line.startswith('#,') and 'fuzzy' in line:
                cur['fuzzy'] = True
                continue
            if not line:
                flush()
                field = None
                continue
            if line.startswith('#'):
                continue
            if line.startswith('"'):
                cur[field] = cur.get(field, '') + ast.literal_eval(line)
                continue
            key, _, val = line.partition(' ')
            if key == 'msgid' and 'msgid' in cur:
                flush()
            field = key
            cur[field] = ast.literal_eval(val)
    flush()
    return entries


def build_mo(entries):
    table = {}
    for e in entries:
        msgid = e['msgid']
        if 'msgid_plural' in e:
            forms = [e[k] for k in sorted(e) if k.startswith('msgstr[')]
            msgstr = '\0'.join(forms)
            msgid = msgid + '\0' + e['msgid_plural']
        else:
            msgstr = e.get('msgstr', '')
        if msgid and not msgstr.replace('\0', ''):
            continue
        if 'msgctxt' in e:
            msgid = e['msgctxt'] + '\x04' + msgid
        table[msgid.encode('utf-8')] = msgstr.encode('utf-8')
    keys = sorted(table)
    ids = b''.join(k + b'\0' for k in keys)
    strs = b''.join(table[k] + b'\0' for k in keys)
    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets, voffsets, pos = [], [], 0
    for k in keys:
        koffsets += [len(k), keystart + pos]
        pos += len(k) + 1
    pos = 0
    for k in keys:
        v = table[k]
        voffsets += [len(v), valuestart + pos]
        pos += len(v) + 1
    header = struct.pack('Iiiiiii', 0x950412DE, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    return header + struct.pack('%dI' % len(koffsets), *koffsets) + struct.pack('%dI' % len(voffsets), *voffsets) + ids + strs


def main():
    root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lingolane', 'languages')
    pos = sorted(glob.glob(os.path.join(root, '*.po')))
    if not pos:
        sys.exit('no .po files found')
    for po in pos:
        data = build_mo(parse_po(po))
        out = po[:-3] + '.mo'
        with open(out, 'wb') as fh:
            fh.write(data)
        print(os.path.relpath(out), len(data), 'bytes')


if __name__ == '__main__':
    main()
