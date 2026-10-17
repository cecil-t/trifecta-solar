#!/usr/bin/env python3
"""
Runs SAM (System Advisor Model) through PySAM for the app. App\\Sam starts it with the venv's Python
(/opt/sam/bin/python in the Docker image), writes one JSON request to stdin and reads one JSON reply
from stdout. Nothing is written to disk.

Request:  {"action": "check"}
Reply:    {"ok": true, ...} or {"ok": false, "error": "..."}

Actions:
	check     Versions, the CEC module and inverter libraries, and one run of SAM's detailed PV model
	          (Pvsamv1) on made-up clear-sky weather. Proves SAM is installed and runs; the kWh from
	          the made-up weather means nothing.
	weather   {"weather_file": path to an NSRDB CSV}: the file's point and yearly irradiance, and
	          SAM's default example system run on it fixed, single-axis and dual-axis (kWh per kW DC),
	          as a sanity check of the weather, with PVWatts v8 set up to match each case for
	          comparison. Not an estimate for any real system.
	bill      {"rate": one URDB rate (OpenEI, full detail), "monthly_kwh": n}: the rate converted
	          for SAM (UtilityRateTools.URDBv8_to_ElectricityRates) and SAM's Utilityrate5 bill
	          for a flat use of n kWh every month with no solar, as a check that the rate reads right.
	system    One real system on a weather file: both SAM's detailed PV model (Pvsamv1) and PVWatts
	          v8, year one by month. Request:
	          {"weather_file",
	           "module": CEC library name, or {"spec": {watts, vmp, imp, voc, isc, alpha_isc_pct,
	              beta_voc_pct, gamma_pmp_pct, cells, noct, length_mm, width_mm, tech, bifacial}}
	              from a spec sheet (SAM's user-entered module model),
	           "inverter": CEC library name, or {"spec": {ac_w, eff_pct, vdc_nominal, mppt_low,
	              mppt_high, vdc_max}} (SAM's inverter datasheet model),
	           "inverter_count", "mlpe": "none" | "optimizers" | "micro" ("optimizers": true also
	              works), "arrays": [{"azimuth", "tilt", "modules", "mount": roof | ground | carport
	              | pole | tracker, "shading": percent}],
	           "losses": {"other", "inverter", "optimizer", "shading"} percent; or "total" in
	              place of "other", as an OpenSolar proposal states it (its total covers inverter,
	              optimizer and other losses; shading comes on top), "sam_adjust": extra percent
	              taken off the detailed model only}.
	          Other losses go in as one DC loss and shading as a flat loss (soiling slot), so both
	          models carry the same assumptions. Trackers are single-axis, north-south, backtracking.

SAM_LIB (environment) is the folder holding cec_modules.csv, cec_inverters.csv and VERSION.
"""
import datetime
import json
import math
import os
import platform
import resource
import sys
import time
import traceback

LIB = os.environ.get('SAM_LIB', '/opt/sam/lib')


def library(name):
	"""Row count of one CEC library file (SAM's files have three header rows)."""
	path = os.path.join(LIB, name)
	if not os.path.isfile(path):
		return {'file': name, 'found': False}
	with open(path, encoding='utf-8', errors='replace') as f:
		rows = sum(1 for line in f if line.strip()) - 3
	return {'file': name, 'found': True, 'rows': rows, 'bytes': os.path.getsize(path)}


def made_up_weather():
	"""A year of hourly clear-sky-ish weather: sun from 6 AM to 6 PM, 15 C, light wind."""
	cols = {k: [] for k in ('year', 'month', 'day', 'hour', 'minute', 'dn', 'df', 'gh', 'tdry', 'wspd')}
	start = datetime.datetime(2023, 1, 1)
	for h in range(8760):
		t = start + datetime.timedelta(hours=h)
		sun = math.sin(math.pi * (t.hour - 6) / 12) if 6 <= t.hour <= 18 else 0.0
		for k, v in (('year', 2023), ('month', t.month), ('day', t.day), ('hour', t.hour), ('minute', 30),
				('dn', 800 * sun), ('df', 100 * sun), ('gh', 700 * sun), ('tdry', 15.0), ('wspd', 2.0)):
			cols[k].append(v)
	cols.update({'lat': 40.0, 'lon': -76.0, 'tz': -5, 'elev': 100})
	return cols


def check(_req):
	import PySAM
	import PySAM.Pvsamv1 as pvsam

	version_file = os.path.join(LIB, 'VERSION')
	reply = {
		'python': platform.python_version(),
		'pysam': PySAM.__version__,
		'library_folder': LIB,
		'library_release': open(version_file).read().strip() if os.path.isfile(version_file) else None,
		'libraries': [library('cec_modules.csv'), library('cec_inverters.csv')],
	}
	t0 = time.time()
	m = pvsam.default('FlatPlatePVNone')
	m.SolarResource.solar_resource_data = made_up_weather()
	m.Losses.use_snow_weather_file = 0   # PySAM 8.0.0's default setup leaves this unset
	m.execute()
	reply['test_run'] = {
		'model': 'Pvsamv1 (detailed PV), SAM default example system, made-up weather',
		'system_kw_dc': round(m.value('system_capacity'), 1),
		'annual_kwh': round(m.Outputs.annual_energy),
		'seconds': round(time.time() - t0, 2),
	}
	return reply


def read_weather_summary(path):
	"""Point, row count and yearly irradiance and temperature from an NSRDB CSV (two metadata lines, then a header row)."""
	import csv
	with open(path, newline='', encoding='utf-8') as f:
		keys = next(csv.reader([f.readline()]))
		vals = next(csv.reader([f.readline()]))
		meta = dict(zip(keys, vals))
		rows = list(csv.DictReader(f))
	num = lambda r, k: float(r[k]) if r.get(k) not in (None, '') else 0.0
	temps = [num(r, 'Temperature') for r in rows]
	return {
		'location_id': meta.get('Location ID'),
		'lat': float(meta['Latitude']), 'lon': float(meta['Longitude']),
		'elevation_m': float(meta.get('Elevation') or 0), 'tz': float(meta.get('Local Time Zone') or meta.get('Time Zone') or 0),
		'hours': len(rows),
		'columns': list(rows[0].keys()) if rows else [],
		'ghi_kwh_m2': round(sum(num(r, 'GHI') for r in rows) / 1000),
		'dni_kwh_m2': round(sum(num(r, 'DNI') for r in rows) / 1000),
		'dhi_kwh_m2': round(sum(num(r, 'DHI') for r in rows) / 1000),
		'temp_avg_c': round(sum(temps) / len(temps), 1) if temps else None,
		'temp_min_c': min(temps) if temps else None,
		'temp_max_c': max(temps) if temps else None,
	}


# Mounting cases for the weather test, on SAM's default example system: [label, detailed model settings, PVWatts array_type]
# PVWatts array types: 0 fixed open rack, 1 fixed roof mount, 2 single-axis, 3 single-axis with backtracking, 4 dual-axis
WEATHER_CASES = [
	('Fixed, 30 degree tilt, due south', {'subarray1_track_mode': 0, 'subarray1_tilt': 30, 'subarray1_azimuth': 180}, 0),
	('Single-axis tracker (north-south axis, backtracking)', {'subarray1_track_mode': 1, 'subarray1_tilt': 0, 'subarray1_azimuth': 180, 'subarray1_backtrack': 1}, 3),
	('Dual-axis tracker', {'subarray1_track_mode': 2}, 4),
]


def pvwatts_like(m, path, array_type):
	"""PVWatts v8 set up to match a detailed-model run: same size, DC/AC ratio, tilt, azimuth and ground
	coverage, its losses combined into one percentage, the inverter's CEC efficiency, and the module's
	bifaciality. Returns kWh per kW DC for year one."""
	import PySAM.Pvwattsv8 as pvwatts

	soiling = sum(m.value('subarray1_soiling')) / 12
	parts = [soiling] + [m.value(k) for k in ('subarray1_mismatch_loss', 'subarray1_diodeconn_loss', 'subarray1_dcwiring_loss',
		'subarray1_tracking_loss', 'subarray1_nameplate_loss', 'dcoptimizer_loss', 'acwiring_loss', 'transmission_loss')]
	keep = 1.0
	for loss in parts:
		keep *= 1 - loss / 100
	kw = m.value('system_capacity')
	w = pvwatts.default('PVWattsNone')
	w.SolarResource.solar_resource_file = path
	sd = w.SystemDesign
	sd.system_capacity = kw
	sd.dc_ac_ratio = kw * 1000 / (m.value('inverter_count') * m.value('inv_snl_paco'))
	sd.array_type = array_type
	sd.tilt = m.value('subarray1_tilt')
	sd.azimuth = m.value('subarray1_azimuth')
	sd.gcr = m.value('subarray1_gcr')
	sd.inv_eff = m.value('inv_snl_eff_cec')
	sd.losses = (1 - keep) * 100
	sd.module_type = 1   # premium (about 21% efficient, like the example's module)
	sd.bifaciality = m.value('cec_bifaciality') if m.value('cec_is_bifacial') else 0
	w.execute()
	return w.Outputs.ac_annual / kw, sd.losses


def weather(req):
	"""Summarize one weather file and run SAM's example system on it for three mounting cases."""
	import PySAM.Pvsamv1 as pvsam

	path = req.get('weather_file')
	if not path or not os.path.isfile(path):
		raise ValueError('Weather file not found.')
	reply = {'weather': read_weather_summary(path), 'runs': []}
	for label, settings, array_type in WEATHER_CASES:
		t0 = time.time()
		m = pvsam.default('FlatPlatePVNone')
		m.SolarResource.solar_resource_file = path
		m.Losses.use_snow_weather_file = 0
		for k, v in settings.items():
			m.value(k, v)
		m.execute()
		kw = m.value('system_capacity')
		detailed = m.Outputs.annual_energy / kw
		seconds = time.time() - t0
		t1 = time.time()
		pvw, pvw_losses = pvwatts_like(m, path, array_type)
		reply['runs'].append({
			'case': label,
			'kwh_per_kw': round(detailed),
			'clip_loss_percent': round(m.Outputs.annual_ac_inv_clip_loss_percent, 1),
			'seconds': round(seconds, 2),
			'pvwatts_kwh_per_kw': round(pvw),
			'pvwatts_vs_detailed_percent': round((pvw - detailed) / detailed * 100, 1),
			'pvwatts_seconds': round(time.time() - t1, 2),
		})
		reply['pvwatts_losses'] = round(pvw_losses, 1)
	reply['system'] = 'SAM default example system: %.0f kW DC, DC/AC ratio %.2f, 5%% soiling' % (
		m.value('system_capacity'), m.value('system_capacity') * 1000 / (m.value('inverter_count') * m.value('inv_snl_paco')))
	return reply


def bill(req):
	"""Convert one URDB rate for SAM and bill a flat monthly use with no solar."""
	import PySAM.Utilityrate5 as utilityrate
	from PySAM.UtilityRateTools import URDBv8_to_ElectricityRates

	rate = req.get('rate')
	if not isinstance(rate, dict):
		raise ValueError('No rate given.')
	monthly_kwh = float(req.get('monthly_kwh') or 1000)
	rates = URDBv8_to_ElectricityRates(rate)
	m = utilityrate.new()
	m.ElectricityRates.assign(rates)
	m.ElectricityRates.rate_escalation = [0]
	m.Lifetime.analysis_period = 1
	m.Lifetime.system_use_lifetime_output = 0
	m.Lifetime.inflation_rate = 0
	m.SystemOutput.gen = [0] * 8760
	m.SystemOutput.degradation = [0]
	# the same use every hour, scaled so each 30-day month is monthly_kwh (31-day months use a bit more)
	m.Load.load = [monthly_kwh * 12 / 8760] * 8760
	m.execute()
	o = m.Outputs
	months = [round(e + f, 2) for e, f in zip(o.year1_monthly_ec_charge_without_system, o.year1_monthly_fixed_without_system)]
	tou = rates.get('ur_ec_tou_mat', [])
	return {
		'monthly_kwh': monthly_kwh,
		'annual_bill': round(o.utility_bill_wo_sys_year1, 2),
		'monthly_bills': months,
		'energy_charges': round(sum(o.year1_monthly_ec_charge_without_system), 2),
		'fixed_charges': round(sum(o.year1_monthly_fixed_without_system), 2),
		'sam_rate': {
			'periods': len({row[0] for row in tou}),
			'tiers': len(tou),
			'monthly_fixed': rates.get('ur_monthly_fixed_charge'),
			'metering': {0: 'Net metering', 2: 'Net billing, instantaneous', 3: 'Net billing, hourly', 4: 'Buy all, sell all'}.get(rates.get('ur_metering_option'), 'Other'),
			'demand_charges': bool(rates.get('ur_dc_enable')),
		},
	}


def cec_row(file_name, name):
	"""One row of a CEC library file as {SAM variable: value}, using the file's third header row."""
	import csv
	path = os.path.join(LIB, file_name)
	with open(path, newline='', encoding='utf-8', errors='replace') as f:
		r = csv.reader(f)
		next(r)
		next(r)
		keys = next(r)
		for row in r:
			if row and row[0] == name:
				out = {}
				for k, v in zip(keys, row):
					if not k or k == '[0]':
						continue
					try:
						out[k] = float(v)
					except ValueError:
						out[k] = v
				return out
	raise ValueError('Not in SAM\'s CEC library (%s): %s' % (file_name, name))


def weather_columns(path):
	"""Column names of an NSRDB CSV (the third line)."""
	import csv
	with open(path, newline='', encoding='utf-8') as f:
		f.readline()
		f.readline()
		return next(csv.reader([f.readline()]))


MOUNTS = ('roof', 'ground', 'carport', 'pole', 'tracker')


def group_arrays(arrays, default_shading=0.0):
	"""Arrays merged by orientation (same mount, tilt within 1 degree and azimuth within 3; trackers
	all together), shading averaged by modules, then the smallest groups folded into their nearest
	neighbor until at most 4 remain (SAM's subarray limit)."""
	groups = []
	for a in arrays:
		n = int(a.get('modules') or 0)
		if n <= 0:
			continue
		mount = a.get('mount') if a.get('mount') in MOUNTS else 'roof'
		az, tilt = float(a.get('azimuth') or 180) % 360, float(a.get('tilt') or 0)
		if mount == 'tracker':
			az, tilt = 180.0, 0.0
		shade = max(0.0, min(90.0, float(a['shading'] if a.get('shading') not in (None, '') else default_shading)))
		for g in groups:
			d = abs((g['azimuth'] - az + 180) % 360 - 180)
			if g['mount'] == mount and abs(g['tilt'] - tilt) <= 1 and d <= 3:
				g['shading'] = (g['shading'] * g['modules'] + shade * n) / (g['modules'] + n)
				g['modules'] += n
				break
		else:
			groups.append({'azimuth': az, 'tilt': tilt, 'modules': n, 'mount': mount, 'shading': shade})
	merged = []
	while len(groups) > 4:
		groups.sort(key=lambda g: g['modules'])
		small = groups.pop(0)
		def dist(g):
			return abs((g['azimuth'] - small['azimuth'] + 180) % 360 - 180) + abs(g['tilt'] - small['tilt']) * 3 + (0 if g['mount'] == small['mount'] else 1000)
		target = min(groups, key=dist)
		merged.append('%d modules at %d/%d folded into %d/%d' % (small['modules'], small['azimuth'], small['tilt'], target['azimuth'], target['tilt']))
		target['shading'] = (target['shading'] * target['modules'] + small['shading'] * small['modules']) / (target['modules'] + small['modules'])
		target['modules'] += small['modules']
	for g in groups:
		g['shading'] = round(g['shading'], 2)
	return groups, merged


def monthly(hourly_or_monthly):
	return [round(v) for v in hourly_or_monthly]


CELL_TECH = {'mono': 0, 'poly': 1, 'cdte': 2, 'cis': 3, 'cigs': 4, 'amorphous': 5}


def spec_num(spec, key, label, low=None, high=None):
	try:
		v = float(spec.get(key))
	except (TypeError, ValueError):
		raise ValueError('Spec sheet: %s is missing.' % label)
	if (low is not None and v < low) or (high is not None and v > high):
		raise ValueError('Spec sheet: %s looks wrong (%g).' % (label, v))
	return v


def module_source(src):
	"""The module, from SAM's library or a spec sheet: {kind, values, w, vmp, voc, bifacial, label}."""
	if isinstance(src, dict) and isinstance(src.get('spec'), dict):
		sp = src['spec']
		vmp = spec_num(sp, 'vmp', 'Vmp', 1, 1500)
		imp = spec_num(sp, 'imp', 'Imp', 0.1, 50)
		voc = spec_num(sp, 'voc', 'Voc', 1, 1500)
		isc = spec_num(sp, 'isc', 'Isc', 0.1, 50)
		if vmp >= voc or imp >= isc:
			raise ValueError('Spec sheet: Vmp must be below Voc and Imp below Isc.')
		area = spec_num(sp, 'length_mm', 'length', 300, 4000) * spec_num(sp, 'width_mm', 'width', 300, 3000) / 1e6
		values = {
			'sixpar_celltech': CELL_TECH.get(str(sp.get('tech') or 'mono'), 0),
			'sixpar_vmp': vmp, 'sixpar_imp': imp, 'sixpar_voc': voc, 'sixpar_isc': isc,
			'sixpar_bvoc': spec_num(sp, 'beta_voc_pct', 'Voc temperature coefficient', -1.5, 0) / 100 * voc,
			'sixpar_aisc': spec_num(sp, 'alpha_isc_pct', 'Isc temperature coefficient', 0, 0.5) / 100 * isc,
			'sixpar_gpmp': spec_num(sp, 'gamma_pmp_pct', 'Pmax temperature coefficient', -1.5, 0),
			'sixpar_nser': int(spec_num(sp, 'cells', 'cells in series', 10, 400)),
			'sixpar_area': area,
			'sixpar_tnoct': spec_num(sp, 'noct', 'NOCT', 30, 70),
		}
		return {'kind': 'spec', 'values': values, 'w': vmp * imp, 'vmp': vmp, 'voc': voc,
			'bifacial': bool(sp.get('bifacial')), 'label': 'spec sheet'}
	name = str(src or '')
	row = cec_row('cec_modules.csv', name)
	return {'kind': 'cec', 'values': row, 'w': row['cec_v_mp_ref'] * row['cec_i_mp_ref'], 'vmp': row['cec_v_mp_ref'],
		'voc': row['cec_v_oc_ref'], 'bifacial': row.get('lib_is_bifacial') == 1, 'label': name}


def inverter_source(src):
	"""The inverter, from SAM's library or a spec sheet: {kind, values, paco, eff_pct, vdco, mppt_low, mppt_high, label}."""
	if isinstance(src, dict) and isinstance(src.get('spec'), dict):
		sp = src['spec']
		paco = spec_num(sp, 'ac_w', 'AC rating', 100, 2000000)
		eff = spec_num(sp, 'eff_pct', 'CEC efficiency', 80, 99.9)
		vdco = spec_num(sp, 'vdc_nominal', 'nominal DC voltage', 20, 1500)
		low = float(sp.get('mppt_low') or vdco * 0.6)
		high = float(sp.get('mppt_high') or vdco * 1.3)
		vmax = float(sp.get('vdc_max') or max(high, vdco) * 1.2)
		values = {'inv_ds_paco': paco, 'inv_ds_eff': eff, 'inv_ds_pnt': paco * 0.0005, 'inv_ds_pso': paco * 0.002,
			'inv_ds_vdco': vdco, 'inv_ds_vdcmax': vmax}
		return {'kind': 'spec', 'values': values, 'paco': paco, 'eff_pct': eff, 'vdco': vdco, 'mppt_low': low, 'mppt_high': high,
			'label': 'spec sheet'}
	name = str(src or '')
	row = cec_row('cec_inverters.csv', name)
	return {'kind': 'cec', 'values': row, 'paco': row['inv_snl_paco'], 'eff_pct': row['inv_snl_paco'] / row['inv_snl_pdco'] * 100,
		'vdco': row['inv_snl_vdco'], 'mppt_low': row['inv_snl_mppt_low'], 'mppt_high': row['inv_snl_mppt_hi'], 'label': name}


def system(req):
	"""Year one of a real system, detailed model and PVWatts v8 with the same assumptions."""
	import PySAM
	import PySAM.Pvsamv1 as pvsam
	import PySAM.Pvwattsv8 as pvwatts

	path = req.get('weather_file')
	if not path or not os.path.isfile(path):
		raise ValueError('Weather file not found.')
	L = req.get('losses') or {}
	groups, merged = group_arrays(req.get('arrays') or [], float(L.get('shading') or 0))
	if not groups:
		raise ValueError('No arrays with modules.')
	mod = module_source(req.get('module'))
	inv = inverter_source(req.get('inverter'))
	inv_count = max(1, int(req.get('inverter_count') or 1))
	mlpe = req.get('mlpe') or ('optimizers' if req.get('optimizers') else 'none')
	fixed_v = mlpe in ('optimizers', 'micro')
	pct = lambda k: max(0.0, min(99.0, float(L.get(k) or 0)))
	inv_loss, opt_loss = pct('inverter'), pct('optimizer') if mlpe == 'optimizers' else 0.0
	if L.get('other') is not None:
		other = pct('other')
	else:
		# OpenSolar's stated total covers inverter, optimizer and other losses; shading is on top of it
		total = pct('total')
		keep_other = (1 - total / 100) / ((1 - inv_loss / 100) * (1 - opt_loss / 100)) if total else 1.0
		other = max(0.0, (1 - keep_other) * 100)
	sam_adjust = max(0.0, min(50.0, float(req.get('sam_adjust') or 0)))
	modules = sum(g['modules'] for g in groups)
	kw_dc = modules * mod['w'] / 1000
	kw_ac = inv_count * inv['paco'] / 1000
	cols = weather_columns(path)
	snow = any('snow' in c.lower() for c in cols)
	any_ground = any(g['mount'] != 'roof' for g in groups)

	# Detailed model
	t0 = time.time()
	m = pvsam.default('FlatPlatePVNone')
	m.SolarResource.solar_resource_file = path
	if mod['kind'] == 'cec':
		m.Module.module_model = 1
		cec = m.CECPerformanceModelWithModuleDatabase
		names = cec.export()
		for k, v in mod['values'].items():
			if k.startswith('cec_') and k in names:
				cec.__setattr__(k, v)
		cec.cec_is_bifacial = 1 if (any_ground and mod['bifacial']) else 0
		cec.cec_bifaciality = 0.7
		cec.cec_temp_corr_mode = 0
		cec.cec_standoff = 6 if any_ground else 1   # rack, or roof with more than 3.5 in. of standoff
		cec.cec_height = 1 if any_ground else 0
	else:
		m.Module.module_model = 2
		sx = m.CECPerformanceModelWithUserEnteredSpecifications
		for k, v in mod['values'].items():
			sx.__setattr__(k, v)
		sx.sixpar_standoff = 6 if any_ground else 1
		sx.sixpar_mounting = 0
		sx.sixpar_is_bifacial = 1 if (any_ground and mod['bifacial']) else 0
		sx.sixpar_bifaciality = 0.7
		sx.sixpar_bifacial_transmission_factor = 0.013
		sx.sixpar_bifacial_ground_clearance_height = 1
		sx.sixpar_transient_thermal_model_unit_mass = 11.0919
	if inv['kind'] == 'cec':
		m.Inverter.inverter_model = 0
		db = m.InverterCECDatabase
		names = db.export()
		for k, v in inv['values'].items():
			if k.startswith('inv_snl_') and k in names:
				db.__setattr__(k, v)
		if fixed_v:
			# Optimizers and micros hold the string at a fixed voltage: no voltage window to fall
			# out of, and the inverter's efficiency at its rated DC voltage.
			db.inv_snl_c1 = db.inv_snl_c2 = db.inv_snl_c3 = 0
			db.inv_snl_vdcmax = 100000
	else:
		m.Inverter.inverter_model = 1
		ds = m.InverterDatasheet
		for k, v in inv['values'].items():
			ds.__setattr__(k, v)
		if fixed_v:
			ds.inv_ds_vdcmax = 100000
	m.Inverter.mppt_low_inverter = 0 if fixed_v else inv['mppt_low']
	m.Inverter.mppt_hi_inverter = 100000 if fixed_v else inv['mppt_high']
	m.Inverter.inv_num_mppt = 1
	m.Inverter.inverter_count = inv_count
	sd = m.SystemDesign
	sd.enable_mismatch_vmax_calc = 0
	sd.inverter_count = inv_count
	for i in range(1, 5):
		g = groups[i - 1] if i <= len(groups) else None
		if i > 1:
			m.value('subarray%d_enable' % i, 1 if g else 0)
		if not g:
			continue
		if mlpe == 'micro':
			per_string, strings = 1, g['modules']
		elif fixed_v:
			per_string, strings = g['modules'], 1
		else:
			per_string = max(1, round(inv['vdco'] / mod['vmp']))
			strings = max(1, round(g['modules'] / per_string))
		tracker = g['mount'] == 'tracker'
		for k, v in (('modules_per_string', per_string), ('nstrings', strings), ('tilt', g['tilt']), ('azimuth', g['azimuth']),
				('track_mode', 1 if tracker else 0), ('backtrack', 1 if tracker else 0), ('rotlim', 52), ('mppt_input', 1),
				('gcr', 0.35 if tracker else (0.4 if g['mount'] != 'roof' else 0.9))):
			m.value('subarray%d_%s' % (i, k), v)
		for k, v in (('mismatch_loss', 0), ('diodeconn_loss', 0), ('dcwiring_loss', other), ('tracking_loss', 0), ('nameplate_loss', 0),
				('rear_soiling_loss', 0), ('rack_shading', 0), ('electrical_mismatch', 0)):
			m.value('subarray%d_%s' % (i, k), v)
		m.value('subarray%d_soiling' % i, [g['shading']] * 12)
	sd.system_capacity = kw_dc
	m.Losses.dcoptimizer_loss = opt_loss
	m.Losses.acwiring_loss = 0
	m.Losses.transmission_loss = 0
	m.Losses.calculate_rack_shading = 0
	m.Losses.en_snow_model = 1 if snow else 0
	m.Losses.use_snow_weather_file = 1 if snow else 0
	m.AdjustmentFactors.adjust_constant = sam_adjust
	m.execute()
	detailed = {
		'model': 'Pvsamv1 (SAM detailed PV)',
		'annual_kwh': round(m.Outputs.annual_energy),
		'monthly_kwh': monthly(m.Outputs.monthly_energy),
		'clip_loss_percent': round(m.Outputs.annual_ac_inv_clip_loss_percent, 2),
		'seconds': round(time.time() - t0, 2),
	}

	# PVWatts v8: one run per group, added up
	t1 = time.time()
	inv_eff = 100 - inv_loss if inv_loss else inv['eff_pct']
	pv_monthly = [0.0] * 12
	for g in groups:
		keep = (1 - other / 100) * (1 - g['shading'] / 100) * (1 - opt_loss / 100)
		w = pvwatts.default('PVWattsNone')
		w.SolarResource.solar_resource_file = path
		s = w.SystemDesign
		s.system_capacity = kw_dc * g['modules'] / modules
		s.dc_ac_ratio = kw_dc / kw_ac
		s.array_type = 3 if g['mount'] == 'tracker' else (1 if g['mount'] == 'roof' else 0)
		s.tilt = g['tilt']
		s.azimuth = g['azimuth']
		s.gcr = 0.35 if g['mount'] == 'tracker' else 0.4
		s.inv_eff = min(99.5, max(90.0, inv_eff))
		s.losses = (1 - keep) * 100
		s.module_type = 1
		s.bifaciality = 0
		w.execute()
		pv_monthly = [a + b for a, b in zip(pv_monthly, w.Outputs.ac_monthly)]
	pvw = {
		'model': 'PVWatts v8',
		'annual_kwh': round(sum(pv_monthly)),
		'monthly_kwh': monthly(pv_monthly),
		'inverter_eff_percent': round(inv_eff, 2),
		'seconds': round(time.time() - t1, 2),
	}
	version_file = os.path.join(LIB, 'VERSION')
	return {
		'kw_dc': round(kw_dc, 3), 'kw_ac': round(kw_ac, 3), 'dc_ac': round(kw_dc / kw_ac, 3),
		'module_w': round(mod['w'], 1), 'modules': modules, 'module_source': mod['kind'], 'inverter_source': inv['kind'],
		'mlpe': mlpe, 'groups': groups, 'merged': merged,
		'other_loss_percent': round(other, 2), 'sam_adjust_percent': sam_adjust,
		'snow_modeled': snow,
		'weather_columns': cols,
		'detailed': detailed, 'pvwatts': pvw,
		'pysam': PySAM.__version__,
		'library_release': open(version_file).read().strip() if os.path.isfile(version_file) else None,
	}

ACTIONS = {'check': check, 'weather': weather, 'bill': bill, 'system': system}


def main():
	started = time.time()
	try:
		req = json.loads(sys.stdin.read() or '{}')
		action = ACTIONS.get(req.get('action'))
		if action is None:
			raise ValueError('Unknown action. Use one of: ' + ', '.join(ACTIONS))
		reply = {'ok': True}
		reply.update(action(req))
	except Exception as e:
		reply = {'ok': False, 'error': '%s: %s' % (type(e).__name__, e), 'trace': traceback.format_exc(limit=5)}
	reply['seconds_total'] = round(time.time() - started, 2)
	reply['peak_memory_mb'] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024)
	sys.stdout.write(json.dumps(reply))


if __name__ == '__main__':
	main()
