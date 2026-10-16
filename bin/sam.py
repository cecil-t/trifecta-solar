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


ACTIONS = {'check': check, 'weather': weather, 'bill': bill}


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
