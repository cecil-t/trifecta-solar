#!/usr/bin/env python3
"""
Turn the Master Buildsheet spreadsheet into presales.json for `bin/console import:presales`.

	python3 tools/import/build_presales.py --xlsx "MASTER BUILDSHEET.xlsx" --policy import/presales_policy.json \
		--out import/presales.json

What it reads from the sheet: every catalog tab (modules, inverters, MLPE, monitoring, batteries,
balance of system, warranty programs, racking, equipment, structural and foundation, permits,
interconnection, trenching and misc) plus the rates on the Build Sheet tab (tax, labor rate,
site visit, markup, commission table, deposits).

What it does not invent: rules that are company policy (contingency rules and cap, deposit tiers,
payment schedules, warranty wording, stale-price days) come from the --policy file, kept in
import/ like all company data. Prices and rates never go in git.

Clean-up done on the way in (the old sheet's duplicate rows become one item):
  - 12 and 25 year SolarEdge inverters become one item with an optional extended warranty
  - "with SIW" battery rows become a SolarInsure cost on the battery (charged only with SolarInsure)
  - "with Third Party Internet" monitoring rows are dropped (the internet is its own item)
  - shipping that the sheet folded into a price is split out where the sheet says how much
  - generators are left out
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
from pathlib import Path

import openpyxl


def clean(v) -> str:
	return re.sub(r'\s+', ' ', str(v)).strip() if v is not None else ''


def num(v) -> float | None:
	if isinstance(v, bool):
		return None
	if isinstance(v, (int, float)):
		return float(v)
	m = re.fullmatch(r'\$?\s*([\d,]+(?:\.\d+)?)', clean(v))
	return float(m.group(1).replace(',', '')) if m else None


def month_date(text: str) -> str | None:
	"""'Krannich Pricing 4-26' -> 2026-04-01."""
	m = re.search(r'\b(\d{1,2})-(\d{2})\b', text or '')
	if not m:
		return None
	mo, yr = int(m.group(1)), 2000 + int(m.group(2))
	return f'{yr:04d}-{mo:02d}-01' if 1 <= mo <= 12 else None


def is_header(name: str) -> bool:
	return name.startswith('-') or name == ''


class Catalog:
	def __init__(self, sheet_date: str, sheet_label: str):
		self.items: list[dict] = []
		self.sheet_date = sheet_date
		self.sheet_label = sheet_label
		self.seen: set[tuple[str, str]] = set()

	def add(self, category: str, name: str, price: float, **kw) -> None:
		name = clean(name)
		if category == 'inverters':
			name = re.sub(r'\s*\(\d+ (YR|Year) W\.?\)', '', name, flags=re.I).strip()
		key = (category, name.lower())
		if key in self.seen:
			return
		self.seen.add(key)
		item = {
			'category': category, 'name': name, 'price': round(float(price or 0), 4),
			'shipping': 0.0, 'setup_cost': 0.0, 'min_total': None, 'qty_rule': 'manual', 'tax_class': 'major',
			'watts': None, 'ac_kw': None, 'kwh': None, 'kw': None, 'warranty_text': None,
			'ext_warranty_years': None, 'ext_warranty_cost': None, 'siw_cost': None, 'is_solarinsure': 0,
			'notes': None, 'print_notes': 0, 'segment': None, 'mounts': None,
			'domestic_content_pct': None, 'feoc_ok': None, 'needs_mlpe': 0,
			'price_updated_at': self.sheet_date, 'price_source': self.sheet_label,
			'sort_order': len(self.items) + 1,
		}
		for k, v in kw.items():
			if v is not None and v != '':
				item[k] = v
		self.items.append(item)


def notes_join(*parts) -> str | None:
	out = [clean(p) for p in parts if clean(p)]
	return '\n'.join(out) or None


def classify(it: dict) -> None:
	"""Which jobs an item is offered on (segment) and which install types it fits (mounts), from
	its category, name and the sheet section it came from. Blank = everywhere. Equipment (modules,
	inverters, MLPE, monitoring) is never limited: residential jobs use "commercial" modules and farms
	use residential inverters. Only the lines priced by job type are: BOS, structural, permits,
	interconnection."""
	cat, name = it['category'], it['name']
	low = name.lower()
	notes = (it['notes'] or '').lower()
	if it['segment'] is None:
		if cat in ('bos', 'structural', 'permits', 'interconnection'):
			if 'residential' in low:
				it['segment'] = 'residential'
			elif 'commercial' in low:
				it['segment'] = 'commercial'
			elif cat in ('structural', 'permits') and re.search(r'\bkw\b|opt out', low):
				it['segment'] = 'commercial'
		elif cat == 'electrical' and 'feeder protection' in low:
			it['segment'] = 'commercial'
	if cat == 'inverters' and 'solaredge' in low.replace(' ', ''):
		it['needs_mlpe'] = 1
	if cat == 'racking':
		if 'flush roof' in notes or 'flat roof' in notes:
			it['mounts'] = 'ground' if 'compactground' in low else 'roof'
		elif 'tracker' in notes or 'tracker' in low:
			it['mounts'] = 'tracker'
		elif re.search(r'ground mount|carport', notes):
			it['mounts'] = 'ground'
	elif cat == 'foundation':
		it['mounts'] = 'tracker' if 'tracker' in low or 'mechatron' in low else 'ground'


def build(xlsx: Path, policy: dict) -> dict:
	wb = openpyxl.load_workbook(xlsx, data_only=True)
	sheet_label = policy.get('sheet_label') or ('Master Buildsheet ' + xlsx.stem)
	cat = Catalog(policy.get('sheet_date') or dt.date.today().isoformat(), sheet_label)

	# ---- Modules: A name, B price, D watts, E dimensions, F warranty, G domestic content note
	ws = wb['Modules']
	for r in range(1, ws.max_row + 1):
		name = clean(ws.cell(r, 1).value)
		if is_header(name) or num(ws.cell(r, 2).value) is None:
			continue
		cat.add('modules', name, num(ws.cell(r, 2).value), watts=num(ws.cell(r, 4).value),
			warranty_text=clean(ws.cell(r, 6).value) or None,
			notes=notes_join(ws.cell(r, 5).value and 'Dimensions: ' + clean(ws.cell(r, 5).value), ws.cell(r, 7).value))

	# ---- Inverters tab, by section header
	ws = wb['Inverters']
	section = ''
	section_note = ''
	pending_ext: dict[str, tuple[float, int]] = {}
	rows = []
	for r in range(1, ws.max_row + 1):
		rows.append([ws.cell(r, c).value for c in range(1, 7)])
	# First pass: SolarEdge 25-year rows give the extended warranty cost for the 12-year item
	for a, b, c, d, e, f in rows:
		name = clean(a)
		if '(25 YR W)' in name and num(d) is not None:
			pending_ext[name.replace('(25 YR W)', '').strip()] = (num(d), 25)
	for a, b, c, d, e, f in rows:
		name = clean(a)
		if not name:
			continue
		if name.startswith('-') or name in ('Inverters', 'Single Module Inverter/MLPE', 'Dual Module Inverter/MLPE', 'Monitoring Equipment'):
			section = name
			section_note = clean(e)
			continue
		price = num(b)
		if price is None or name == 'None':
			continue
		low = section.lower()
		src_date = month_date(section_note) or cat.sheet_date
		src = (section_note.rstrip(':') + ' (' + sheet_label + ')') if section_note else sheet_label
		note = notes_join(f)
		if name == 'Production Meter':
			cat.add('electrical', name, price, tax_class='bos', notes=note)
		elif 'Fire Fighter Switch' in name:
			# The notes column beside the next few rows belongs to the switch, not to those inverters
			cat.add('electrical', name, price, tax_class='bos', notes=notes_join(*[row[5] for row in rows[3:7]]))
		elif 'solaredge home hub' in low and '25 yr' in low:
			continue  # folded into the 12-year item as its extended warranty
		elif 'solaredge home hub' in low:
			base = name.replace('(12 YR W)', '').strip()
			ext = pending_ext.get(base)
			cat.add('inverters', base, price, ac_kw=num(c), warranty_text='12 year factory warranty',
				ext_warranty_years=ext[1] if ext else None, ext_warranty_cost=ext[0] if ext else None,
				price_updated_at=src_date, price_source=src)
		elif 'sma sunny boy' in low or 'sma 3 phase' in low:
			dist = num(e)
			years = 25 if 'sunny boy' in low else 20
			cat.add('inverters', name, dist if dist else price, shipping=round(price - dist, 2) if dist else 0.0,
				ac_kw=num(c), warranty_text='10 year factory warranty', ext_warranty_years=years if num(d) else None,
				ext_warranty_cost=num(d), price_updated_at=src_date, price_source=src, notes=note)
		elif 'growatt' in low:
			dist = num(e)
			m = re.search(r'\$([\d,]+) shipping', section_note)
			ship = float(m.group(1).replace(',', '')) if m else 0.0
			base = dist if dist is not None else price - ship
			cat.add('inverters', name, base, shipping=ship, ac_kw=num(c), warranty_text='10 year factory warranty',
				price_updated_at=src_date, price_source=src,
				notes=notes_join(note, None if dist is not None else 'Sheet note: ' + clean(e)))
		elif 'eg4' in low:
			cat.add('inverters', name, price, ac_kw=num(c), notes=note)
		elif 'solaredge 3 phase' in low or 'cps 3 phase' in low:
			years = 20
			ext_cost = num(d)
			cat.add('inverters', name, price, ac_kw=num(c), warranty_text='12 year factory warranty' if 'solaredge' in low else '10 year factory warranty',
				ext_warranty_years=years if ext_cost else None, ext_warranty_cost=ext_cost,
				price_updated_at=src_date, price_source=src,
				notes=notes_join(note, None if ext_cost or d is None else str(years) + ' year warranty cost (sheet): ' + clean(d)))
		elif section == 'Single Module Inverter/MLPE' or section == 'Dual Module Inverter/MLPE':
			rule = 'modules' if section.startswith('Single') else 'module_pairs'
			m = re.search(r'\$(\d+(?:\.\d+)?) shipping|shipping \$(\d+(?:\.\d+)?)', clean(d) + ' ' + clean(e))
			ship = float(m.group(1) or m.group(2)) if m else 0.0
			cat.add('mlpe', name, price - ship, shipping=ship, qty_rule=rule, ac_kw=num(c),
				warranty_text='25 year warranty' if '25 Year W' in name else None,
				notes=notes_join(d if ship == 0 else None, e if isinstance(e, str) else None, f))
		elif section == 'Monitoring Equipment':
			if 'with Third Party Internet' in name:
				continue  # the internet service is its own item
			if 'with Cellular Modem' in name:
				m = re.search(r'Cell Modem is \$([\d,]+)', clean(d))
				cat.add('monitoring', 'Enphase Cell Modem', float(m.group(1).replace(',', '')) if m else price, notes='One per system, never one per inverter.')
				continue
			cat.add('monitoring', name, price, notes=notes_join(d))

	# ---- Batteries: packages and add-ons without SolarInsure; the with-SIW rows give the SIW cost
	ws = wb['Backup Components']
	siw = {}
	for r in range(1, ws.max_row + 1):
		name, notes = clean(ws.cell(r, 1).value), clean(ws.cell(r, 3).value)
		m = re.search(r'SIW \$([\d,]+)', notes)
		if m:
			key = re.sub(r'\b(WITH SIW|\(WITH SIW\))', '', name, flags=re.I).strip().lower()
			siw[key.split(' battery')[0].split(' package')[0]] = float(m.group(1).replace(',', ''))
	section = ''
	for r in range(1, ws.max_row + 1):
		name = clean(ws.cell(r, 1).value)
		price = num(ws.cell(r, 2).value)
		if name.startswith('-') or name.lower().startswith('kohler'):
			section = name.lower()
			continue
		if 'generator' in section or 'kohler' in name.lower() or price is None or name == 'None':
			continue
		if 'with siw' in name.lower() or 'with solar insure' in section and 'additional' not in section:
			continue
		base = re.sub(r'\s*\((without SIW|For Sol Ark, without SIW)\)', '', name, flags=re.I).strip()
		if 'additional' in section:
			base = re.sub(r'\s*Battery Package', '', base) + (' (add-on)' if 'add-on' not in base else '')
		siw_key = next((k for k in siw if base.lower().startswith(k)), None)
		kwh = None
		m = re.search(r'([\d.]+)\s*kwh', name, re.I)
		if m:
			kwh = float(m.group(1))
		elif 'IQ 5P' in name:
			kwh = 5.0
		elif 'Encharge 10' in name:
			kwh = 10.0
		cat.add('batteries', base, price, kwh=kwh, siw_cost=siw.get(siw_key) if siw_key else None,
			notes=notes_join(ws.cell(r, 3).value))

	# ---- BoS tab: balance of system per watt (with the sheet's minimum), warranty programs
	ws = wb['BoS']
	bos_min = policy.get('bos_minimum')
	for r in range(2, ws.max_row + 1):
		name, price = clean(ws.cell(r, 1).value), num(ws.cell(r, 2).value)
		if name and name != 'None' and price is not None:
			cat.add('bos', 'Balance of System, ' + name, price, qty_rule='dc_watts', tax_class='bos', min_total=bos_min)
	for r in range(2, ws.max_row + 1):
		name, price = clean(ws.cell(r, 3).value), num(ws.cell(r, 4).value)
		if name and name != 'None' and price is not None:
			cat.add('warranty', name, price, qty_rule='dc_watts', tax_class='none',
				is_solarinsure=1 if 'solar insure' in name.lower() else 0)

	# ---- Racking: per watt plus a one-time adder (column C)
	ws = wb['Racking']
	section = ''
	for r in range(2, ws.max_row + 1):
		name, price = clean(ws.cell(r, 1).value), num(ws.cell(r, 2).value)
		if name.startswith('-'):
			section = name.strip('- ').title()
			continue
		if not name or name == 'None' or price is None:
			continue
		cat.add('racking', name, price, qty_rule='dc_watts', setup_cost=num(ws.cell(r, 3).value) or 0.0,
			notes=notes_join(section and 'Type: ' + section, ws.cell(r, 4).value, ws.cell(r, 5).value,
				num(ws.cell(r, 6).value) and 'Unit cost: $' + format(num(ws.cell(r, 6).value), ',.0f'), ws.cell(r, 7).value))

	# ---- Equipment rental, structural, foundation, permits, interconnection
	def simple(tab: str, category: str, tax: str, start: int = 1, stop_at: str | None = None, begin_at: str | None = None):
		ws = wb[tab]
		on = begin_at is None
		for r in range(start, ws.max_row + 1):
			name, price = clean(ws.cell(r, 1).value), num(ws.cell(r, 2).value)
			if begin_at and name == begin_at:
				on = True
				continue
			if stop_at and name.startswith(stop_at):
				break
			if not on or not name or name == 'None' or name.startswith('-') or price is None:
				continue
			cat.add(category, name, price, tax_class=tax, notes=notes_join(ws.cell(r, 3).value if isinstance(ws.cell(r, 3).value, str) else None))

	simple('Equipment', 'equipment', 'included')
	simple('StructuralFoundation', 'structural', 'none', stop_at='----- Foundation')
	simple('StructuralFoundation', 'foundation', 'included', begin_at='----- Foundation')
	simple('PermitInterc', 'permits', 'none', stop_at='Interconnection')
	simple('PermitInterc', 'interconnection', 'none', begin_at='Interconnection')

	# ---- Trenching and boring: short names, the wire-size detail goes to notes
	ws = wb['Trenching & Misc']
	section = ''
	kind = ''
	for r in range(1, ws.max_row + 1):
		name = clean(ws.cell(r, 1).value)
		if not name:
			continue
		if name.startswith('Trenching & Linear Boring, AC'):
			kind = 'AC'
			continue
		if name.startswith('Trenching & Linear Boring, DC'):
			kind = 'DC'
			continue
		if name.startswith('Miscellaneous'):
			kind = 'misc'
			continue
		if name.startswith('-'):
			low = name.lower()
			section = 'boring' if 'boring' in low else 'customer' if 'customer' in low else 'trench'
			continue
		price = num(ws.cell(r, 2).value)
		if price is None or name == 'None':
			continue
		detail = notes_join(ws.cell(r, 4).value, ws.cell(r, 5).value)
		if kind == 'misc':
			if 'Critter' in name or 'Snow' in name:
				cat.add('electrical', name.replace(', Select Feet', ''), price, tax_class='bos',
					notes='Priced per foot.' if 'Select Feet' in name else None)
			else:
				cat.add('electrical', name, price, tax_class='included')
			continue
		how = {'trench': 'trench (we dig)', 'customer': 'wire only (customer trench)', 'boring': 'linear bore'}[section]
		if kind == 'AC':
			m = re.search(r'<\s*(\d+)\s*kW\s+(\d+)A\s+(\S+)\s+Wire\s+<\s*(\d+)', name)
			short = f'AC {how}: up to {m.group(1)} kW / {m.group(2)} A' if m else name
			wire = re.search(r'(\S+ Wire <\d+\'?.*)$', name)
			notes = notes_join(wire and 'Wire: ' + wire.group(1), detail, 'Original: ' + name)
		else:
			m = re.search(r'(\d+) String Pairs of (#\d+) Wire\s*<?\s*(\d+)?\'?(?:-(\d+))?', name)
			if m:
				rng = f'{m.group(3)}-{m.group(4)} ft' if m.group(4) else f'under {m.group(3)} ft'
				short = f'DC {how}: {m.group(1)} string pairs, {m.group(2)} wire, {rng}'
			else:
				short = name
			notes = notes_join(detail, 'Original: ' + name)
		cat.add('sitework', short, price, setup_cost=num(ws.cell(r, 3).value) or 0.0, tax_class='included', notes=notes, print_notes=1)

	# ---- Rates from the Build Sheet tab and the BoS lists
	bs = wb['Build Sheet']
	bos = wb['BoS']
	settings = {
		'tax_rate': '0.06',
		'tax_note': 'PA Tax',
		'labor_rate': str(num(bs['E7'].value) or ''),
		'site_visit': str(num(bs['E17'].value) or ''),
		'markup_default': str(num(bs['C11'].value) or ''),
	}
	commission = []
	for r in range(2, bos.max_row + 1):
		rate, adj, step = num(bos.cell(r, 12).value), num(bos.cell(r, 13).value), num(bos.cell(r, 14).value)
		if rate is not None and adj is not None:
			commission.append({'rate': rate, 'adjusted': adj, 'step_down': step or 0.0,
				'is_default': 1 if abs(rate - (num(bs['C12'].value) or -1)) < 1e-9 else 0})

	for it in cat.items:
		classify(it)
	settings.update({k: str(v) for k, v in (policy.get('settings') or {}).items()})
	return {
		'kind': 'presales',
		'source': xlsx.name,
		'settings': settings,
		'commission_rates': commission,
		'deposit_tiers': policy.get('deposit_tiers', []),
		'payment_templates': policy.get('payment_templates', []),
		'contingency_rules': policy.get('contingency_rules', []),
		'catalog': cat.items,
	}


def main() -> None:
	ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
	ap.add_argument('--xlsx', required=True, type=Path)
	ap.add_argument('--policy', type=Path, help='JSON with company rules (contingency, deposits, schedules, wording)')
	ap.add_argument('--out', required=True, type=Path)
	a = ap.parse_args()
	policy = json.loads(a.policy.read_text()) if a.policy else {}
	data = build(a.xlsx, policy)
	a.out.write_text(json.dumps(data, indent='\t'))
	counts = {}
	for it in data['catalog']:
		counts[it['category']] = counts.get(it['category'], 0) + 1
	print(f"{len(data['catalog'])} catalog items: " + ', '.join(f'{k} {v}' for k, v in counts.items()))


if __name__ == '__main__':
	main()
