#!/usr/bin/env python3
"""
Runs SAM (System Advisor Model) through PySAM for the app. App\\Sam starts it with the venv's Python
(/opt/sam/bin/python in the Docker image), writes one JSON request to stdin and reads one JSON reply
from stdout. Nothing is written to disk.

Request:  {"action": "check"}
Reply:    {"ok": true, ...} or {"ok": false, "error": "..."}

Actions:
	check   Versions, the CEC module and inverter libraries, and one run of SAM's detailed PV model
	        (Pvsamv1) on made-up clear-sky weather. Proves SAM is installed and runs; the kWh from
	        the made-up weather means nothing.

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


ACTIONS = {'check': check}


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
