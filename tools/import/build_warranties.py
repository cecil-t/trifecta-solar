#!/usr/bin/env python3
"""
Warranty lines for existing projects, by way of a spreadsheet someone fills in.

1. Make the blank sheet from a database backup: one row per equipment line (modules get a
   product row and a performance row), with the years left blank.

	python3 tools/import/build_warranties.py template --db backups/trifecta.sqlite --out import/warranties.csv

2. Fill in provider, covers, term_years (and start_basis or note where needed). Leave term_years
   blank on any row that shouldn't be imported. Kinds: module_product, module_performance,
   inverter, inverter_extended, mlpe, battery, racking, production_guarantee, other.
   start_basis: install (default), inspection, pto, contract.

3. Turn the filled sheet into a file for `bin/console import:updates` (add-only: a line with the
   same kind, provider and covers already on the project is left alone).

	python3 tools/import/build_warranties.py build --csv import/warranties.csv --out import/warranties.json

Both files name customers and projects: keep them in import/ (gitignored), never commit them.
"""
from __future__ import annotations

import argparse
import csv
import json
import sqlite3
from pathlib import Path

COLUMNS = ['project_number', 'project_name', 'equipment', 'qty', 'description', 'kind', 'provider', 'covers',
	'term_years', 'start_basis', 'note']
KINDS = {'module_product', 'module_performance', 'inverter', 'inverter_extended', 'mlpe', 'battery', 'racking',
	'production_guarantee', 'other'}
BASES = {'install', 'inspection', 'pto', 'contract'}


def template(db: Path, out: Path) -> None:
	con = sqlite3.connect(db)
	rows = []
	projects = con.execute("""SELECT id, project_number, name FROM projects
		WHERE (hold_state IS NULL OR hold_state <> 'cancelled') AND is_legacy = 0 ORDER BY project_number""").fetchall()
	for pid, number, name in projects:
		for qty, watts, desc in con.execute('SELECT qty, watts, description FROM project_modules WHERE project_id = ? ORDER BY sort_order', (pid,)):
			label = f'{desc or ""} ({watts:g} W)'.strip()
			rows.append([number, name, 'modules', qty, label, 'module_product', '', '', '', 'install', ''])
			rows.append([number, name, 'modules', qty, label, 'module_performance', '', '', '', 'install', ''])
		for qty, ac_kw, desc in con.execute('SELECT qty, ac_kw, description FROM project_inverters WHERE project_id = ? ORDER BY sort_order', (pid,)):
			rows.append([number, name, 'inverters', qty, f'{desc or ""} ({ac_kw:g} kW AC)'.strip(), 'inverter', '', '', '', 'install', ''])
		for qty, kwh, desc in con.execute('SELECT qty, kwh, description FROM project_batteries WHERE project_id = ? ORDER BY sort_order', (pid,)):
			rows.append([number, name, 'batteries', qty, f'{desc or ""}' + (f' ({kwh:g} kWh)' if kwh else ''), 'battery', '', '', '', 'install', ''])
	with out.open('w', newline='') as f:
		w = csv.writer(f)
		w.writerow(COLUMNS)
		w.writerows(rows)
	print(f'{len(rows)} rows for {len(projects)} projects -> {out}')


def build(src: Path, out: Path) -> None:
	by_project: dict[str, list] = {}
	skipped, problems = 0, []
	with src.open(newline='') as f:
		for n, r in enumerate(csv.DictReader(f), start=2):
			years = (r.get('term_years') or '').strip()
			if not years:
				skipped += 1
				continue
			kind = (r.get('kind') or '').strip()
			basis = (r.get('start_basis') or 'install').strip() or 'install'
			try:
				y = float(years)
			except ValueError:
				y = 0
			if kind not in KINDS or basis not in BASES or not 0 < y <= 50:
				problems.append(f'row {n}: kind "{kind}", term_years "{years}", start_basis "{basis}"')
				continue
			line = {'kind': kind, 'term_years': y, 'start_basis': basis}
			for k in ('provider', 'covers', 'note'):
				if (r.get(k) or '').strip():
					line[k] = r[k].strip()
			by_project.setdefault(r['project_number'].strip(), []).append(line)
	if problems:
		raise SystemExit('Fix these rows first:\n  ' + '\n  '.join(problems))
	data = {'projects': [{'project_number': k, 'warranties': v} for k, v in by_project.items()]}
	out.write_text(json.dumps(data, indent=1))
	print(f'{sum(len(v) for v in by_project.values())} warranty lines for {len(by_project)} projects -> {out} ({skipped} rows left blank)')


def main() -> None:
	ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
	sub = ap.add_subparsers(dest='cmd', required=True)
	t = sub.add_parser('template')
	t.add_argument('--db', required=True, type=Path)
	t.add_argument('--out', required=True, type=Path)
	b = sub.add_parser('build')
	b.add_argument('--csv', required=True, type=Path)
	b.add_argument('--out', required=True, type=Path)
	a = ap.parse_args()
	template(a.db, a.out) if a.cmd == 'template' else build(a.csv, a.out)


if __name__ == '__main__':
	main()
