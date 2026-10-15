#!/usr/bin/env python3
"""Build commissions.json from the salespeople's commission spreadsheets.

Each spreadsheet has one sheet per year (named 2024, 2025, ...) with a header row that starts
"Project Name" and columns for the contract date, Project Amount, Commission, Step Down,
REAP Extra, Self Gen Bonus, Site Visits, Total Amount and Referrals. Columns are found by their
header text, so a sheet whose columns moved still reads. Amounts are taken as the sheet recorded
them; the importer never refigures them.

Usage:
	python3 tools/import/build_commissions.py import/commissions.json "Pat Example=sheets/pat.xlsx" ["Name=file.xlsx" ...]

The output holds customer names and amounts, so it goes in import/ (gitignored), never in git.
Then: php bin/console import:commissions import/commissions.json --dry-run
"""
import datetime
import json
import re
import sys

import openpyxl

HEADERS = {
	'name': ('project name',),
	'amount': ('project amount',),
	'commission': ('commission',),
	'step': ('step down',),
	'reap': ('reap extra', 'reap'),
	'self_gen': ('self gen bonus', 'self gen'),
	'site': ('site visits', 'site visit'),
	'total': ('total amount',),
	'notes': ('referrals', 'notes'),
}


def clean(v):
	if v is None:
		return ''
	return re.sub(r'\s+', ' ', str(v)).strip()


def num(v):
	if v is None or v == '':
		return 0.0
	if isinstance(v, (int, float)):
		return float(v)
	s = re.sub(r'[^0-9.\-]', '', str(v))
	try:
		return float(s) if s not in ('', '-', '.') else 0.0
	except ValueError:
		return 0.0


def header_map(row):
	"""Column index by field, from a header row."""
	found = {}
	for i, cell in enumerate(row):
		text = clean(cell).lower()
		for field, names in HEADERS.items():
			if field not in found and text in names:
				found[field] = i
	return found


def read_sheet(ws, salesperson, source):
	rows = []
	cols = None
	for row in ws.iter_rows(values_only=True):
		if any(clean(c).lower() == 'project name' for c in row):
			cols = header_map(row)
			continue
		if not cols or 'name' not in cols or 'amount' not in cols:
			continue
		name = clean(row[cols['name']]) if cols['name'] < len(row) else ''
		amount = num(row[cols['amount']]) if cols['amount'] < len(row) else 0.0
		if not name or name.lower().startswith('total') or amount <= 0:
			continue
		get = lambda f: row[cols[f]] if f in cols and cols[f] < len(row) else None
		signed = next((c for c in row if isinstance(c, (datetime.datetime, datetime.date))), None)
		commission = num(get('commission')) + num(get('step'))
		self_gen = num(get('self_gen'))
		reap = num(get('reap'))
		site = num(get('site'))
		payouts = reap + site
		parts = []
		if site:
			parts.append('Site visit $%s' % format(site, ',.0f'))
		if reap:
			parts.append('REAP $%s' % format(reap, ',.0f'))
		notes = ' '.join(clean(c) for i, c in enumerate(row) if i > cols.get('total', 99) and clean(c))
		sheet_total = num(get('total'))
		total = commission + self_gen + payouts
		rows.append({
			'salesperson': salesperson,
			'job_name': name,
			'signed_on': signed.strftime('%Y-%m-%d') if signed else None,
			'contract_amount': amount,
			'commission': round(commission, 2),
			'self_gen': round(self_gen, 2),
			'payouts': round(payouts, 2),
			'payouts_note': ', '.join(parts) or None,
			'notes': notes or None,
			'status': 'cancelled' if re.search(r'cancel', notes, re.I) else 'active',
			'sheet_total': sheet_total,
			'total_differs': bool(sheet_total) and abs(sheet_total - total) > 0.5,
			'source': source,
		})
	return rows


def main():
	if len(sys.argv) < 3:
		sys.exit(__doc__)
	out = sys.argv[1]
	rows = []
	for spec in sys.argv[2:]:
		if '=' not in spec:
			sys.exit('Give each sheet as "Salesperson Name=file.xlsx"')
		person, path = spec.split('=', 1)
		wb = openpyxl.load_workbook(path, data_only=True)
		for ws in wb.worksheets:
			if re.fullmatch(r'\d{4}', ws.title.strip()):
				got = read_sheet(ws, person.strip(), ws.title.strip() + ' tab')
				rows.extend(got)
				print('%s, %s: %d jobs' % (person.strip(), ws.title, len(got)))
	with open(out, 'w') as f:
		json.dump({'kind': 'commissions', 'rows': rows}, f, indent=1)
	differs = [r for r in rows if r['total_differs']]
	print('Wrote %d jobs to %s' % (len(rows), out))
	for r in differs:
		print('  check %s: sheet total %s, columns add up to %s' % (r['job_name'], r['sheet_total'], r['commission'] + r['self_gen'] + r['payouts']))


if __name__ == '__main__':
	main()
