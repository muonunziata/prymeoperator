#!/usr/bin/env python3
"""Builds languages/*.po, *.mo and the script translation JSON from es.py.

Usage: python3 bin/i18n/build.py   (from the plugin folder)
"""
import hashlib
import json
import os
import re
import struct
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
sys.path.insert(0, HERE)
from es import PLURAL, SINGULAR  # noqa: E402

DOMAIN = 'prymeoperator'
SCRIPT = 'assets/admin.js'
LOCALES = ['es_ES', 'es_MX', 'es_AR', 'es_CO', 'es_CL', 'es_PE', 'es_VE', 'es_UY', 'es_EC', 'es_GT', 'es_CR', 'es_DO', 'es_PR']
PLURAL_FORMS = 'nplurals=2; plural=(n != 1);'


def js_strings():
    src = open(os.path.join(ROOT, SCRIPT), encoding='utf-8').read()
    found = set(m.group(1) for m in re.finditer(r"__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'prymeoperator'", src))
    found |= set(m.group(1) for m in re.finditer(r"_n\(\s*'((?:[^'\\]|\\.)*)'", src))
    return {s.replace("\\'", "'") for s in found}


def write_mo(path, entries):
    """entries: dict msgid(str, may contain \\0 for plurals) -> msgstr(str)."""
    keys = sorted(entries)
    ids = [k.encode('utf-8') for k in keys]
    strs = [entries[k].encode('utf-8') for k in keys]
    n = len(keys)
    o_ids = 28
    o_strs = o_ids + n * 8
    data_start = o_strs + n * 8
    id_table, str_table, blob = [], [], b''
    for s in ids:
        id_table.append((len(s), data_start + len(blob)))
        blob += s + b'\0'
    for s in strs:
        str_table.append((len(s), data_start + len(blob)))
        blob += s + b'\0'
    # Empty hash table, but its offset must follow the tables: WordPress' MO reader checks it.
    out = struct.pack('<7I', 0x950412de, 0, n, o_ids, o_strs, 0, data_start)
    for length, off in id_table + str_table:
        out += struct.pack('<2I', length, off)
    open(path, 'wb').write(out + blob)


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def main():
    missing = sorted(s for s in js_strings() if s not in SINGULAR and s not in PLURAL)
    if missing:
        print('Missing translations:\n  ' + '\n  '.join(missing))
        sys.exit(1)

    lang_dir = os.path.join(ROOT, 'languages')
    os.makedirs(lang_dir, exist_ok=True)
    md5 = hashlib.md5(SCRIPT.encode()).hexdigest()

    for locale in LOCALES:
        header = 'Content-Type: text/plain; charset=UTF-8\nLanguage: %s\nPlural-Forms: %s\n' % (locale, PLURAL_FORMS)

        mo = {'': header}
        mo.update(SINGULAR)
        for single, (plural, t1, t2) in PLURAL.items():
            mo[single + '\0' + plural] = t1 + '\0' + t2
        write_mo(os.path.join(lang_dir, '%s-%s.mo' % (DOMAIN, locale)), mo)

        po = ['msgid ""', 'msgstr ""'] + ['"%s\\n"' % po_escape(l) for l in header.strip().split('\n')] + ['']
        for k, v in SINGULAR.items():
            po += ['msgid "%s"' % po_escape(k), 'msgstr "%s"' % po_escape(v), '']
        for single, (plural, t1, t2) in PLURAL.items():
            po += ['msgid "%s"' % po_escape(single), 'msgid_plural "%s"' % po_escape(plural),
                   'msgstr[0] "%s"' % po_escape(t1), 'msgstr[1] "%s"' % po_escape(t2), '']
        open(os.path.join(lang_dir, '%s-%s.po' % (DOMAIN, locale)), 'w', encoding='utf-8').write('\n'.join(po))

        messages = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': PLURAL_FORMS}}
        for k, v in SINGULAR.items():
            messages[k] = [v]
        for single, (plural, t1, t2) in PLURAL.items():
            messages[single] = [t1, t2]
        payload = {'domain': 'messages', 'locale_data': {'messages': messages}}
        with open(os.path.join(lang_dir, '%s-%s-%s.json' % (DOMAIN, locale, md5)), 'w', encoding='utf-8') as fh:
            json.dump(payload, fh, ensure_ascii=False)

    print('Built %d locales, %d strings.' % (len(LOCALES), len(SINGULAR) + len(PLURAL)))


if __name__ == '__main__':
    main()
