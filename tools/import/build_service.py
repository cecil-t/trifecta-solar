#!/usr/bin/env python3
"""
Turn the SERVICE Tracker spreadsheet into service.json for `bin/console import:service`.

	python3 tools/import/build_service.py --xlsx "SERVICE Tracker.xlsx" --out import/service.json

Output contains customer names and addresses: keep it in import/ (gitignored), never commit it.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
from pathlib import Path

import openpyxl

# Crew names to pick out of the visit notes come from import/overrides.json (gitignored, like
# all names): {"CREW_NAMES": ["First L", ...]}. Without it, visits are imported with no crew.
_OVERRIDES = Path(__file__).resolve().parents[2] / 'import' / 'overrides.json'
CREW_NAMES = json.loads(_OVERRIDES.read_text()).get('CREW_NAMES', []) if _OVERRIDES.is_file() else []
COVERAGE = {'billable': 'billable', 'warranty': 'warranty', 'solarinsure': 'solarinsure'}


def clean(v) -> str:
	return re.sub(r'\s+', ' ', str(v)).strip() if v is not None else ''


def parse_site(text: str) -> dict | None:
	t = clean(text)
	if not t:
		return None
	m = re.match(r'^(.*?),\s*([^,]+?),\s*([A-Z]{2})\.?\s*(\d{5})?$', t)
	if m:
		return {'street': m.group(1).strip(), 'city': m.group(2).strip(), 'state': m.group(3), 'zip': m.group(4)}
	return {'street': t, 'city': None, 'state': 'PA', 'zip': None}


def dates_in(text: str, opened: dt.date) -> list[str]:
	out = []
	for m in re.finditer(r'\b(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?\b', text):
		mo, d, y = int(m.group(1)), int(m.group(2)), m.group(3)
		year = (2000 + int(y) if len(y) == 2 else int(y)) if y else opened.year
		try:
			out.append(dt.date(year, mo, d).isoformat())
		except ValueError:
			pass
	return out


def main() -> None:
	ap = argparse.ArgumentParser()
	ap.add_argument('--xlsx', required=True)
	ap.add_argument('--sheet', default='2026')
	ap.add_argument('--out', required=True)
	a = ap.parse_args()

	ws = openpyxl.load_workbook(a.xlsx, data_only=True)[a.sheet]
	tickets = []
	for r in ws.iter_rows(min_row=2, values_only=True):
		r = list(r) + [None] * 12
		number = clean(r[0]).upper()
		if not re.fullmatch(r'S\d{5,}', number):
			continue
		opened = r[1].date() if isinstance(r[1], dt.datetime) else None
		if not opened:
			continue
		notes: list[str] = []
		visits_text = clean(r[11])
		trips = int(r[9]) if isinstance(r[9], (int, float)) else 0
		hours = float(r[10]) if isinstance(r[10], (int, float)) else None

		# completed: "X" means done; the sheet has no date, so use the last visit date (or opened)
		done_cell = clean(r[7]).upper()
		found = dates_in(visits_text, opened)
		completed_on = None
		if done_cell == 'X':
			completed_on = max(found) if found else opened.isoformat()
			notes.append('completed date ' + ('taken from the last visit' if found else 'unknown, used the opened date'))
		elif done_cell:
			notes.append(f'Completed column said "{clean(r[7])}"')

		# invoiced: an amount means billed; "N/A" means no invoice; anything else becomes a note
		inv = r[8]
		amount, invoiced, billing_note = None, False, None
		if isinstance(inv, (int, float)):
			amount, invoiced = float(inv), True
			notes.append('invoice date and # not in the spreadsheet')
		elif clean(inv) and clean(inv).upper() not in ('N/A', 'NA'):
			billing_note = f'Spreadsheet invoiced column: {clean(inv)}'

		# visits: one per date when the dates match the trip count, otherwise one summary row
		crew = [n for n in CREW_NAMES if re.search(r'\b' + re.escape(n) + r'\b', visits_text)]
		if '+1' in visits_text:
			crew.append('+1')
		crew_text = ', '.join(crew) or None
		note = visits_text or None
		if visits_text and re.search(r'with travel', visits_text, re.I):
			note += ' [spreadsheet hours include travel]'
		visits = []
		if trips > 1 and len(found) == trips:
			each = round(hours / trips, 2) if hours is not None else None
			for d in found:
				visits.append({'date': d, 'crew': crew_text, 'hours': each, 'trips': 1, 'note': note})
		elif trips or hours or visits_text:
			visits.append({'date': found[0] if found else None, 'crew': crew_text, 'hours': hours,
						   'trips': trips if trips else (1 if hours else 0), 'note': note})

		install = clean(r[5]).upper()
		tickets.append({
			'number': number, 'opened_on': opened.isoformat(), 'customer': clean(r[2]),
			'site': parse_site(r[3]), 'description': clean(r[4]),
			'trifecta_install': 1 if install == 'YES' else 0 if install == 'NO' else None,
			'coverage': COVERAGE.get(clean(r[6]).lower().replace(' ', '')),
			'completed_on': completed_on, 'amount': amount, 'invoiced': invoiced, 'billing_note': billing_note,
			'visits': visits, 'notes': notes,
		})

	tickets.sort(key=lambda t: t['number'])
	Path(a.out).parent.mkdir(parents=True, exist_ok=True)
	Path(a.out).write_text(json.dumps({'generated': dt.datetime.now().isoformat(timespec='seconds'),
									   'source': Path(a.xlsx).name, 'tickets': tickets}, indent=1))
	print(f'{len(tickets)} tickets -> {a.out}')


if __name__ == '__main__':
	main()
