-- Inverter monitoring: systems read from the vendor portals by the monitor:poll collector, their daily
-- production, and a log of collector runs (vendor call counts, for staying inside each vendor's limits).

CREATE TABLE monitor_sites (
	id INTEGER PRIMARY KEY,
	vendor TEXT NOT NULL,                  -- solaredge, enphase, apsystems
	vendor_site_id TEXT NOT NULL,
	name TEXT NOT NULL DEFAULT '',
	capacity_kw REAL,                      -- DC size as the vendor reports it
	state TEXT NOT NULL DEFAULT 'unknown', -- ok, alert, offline, pending, unknown
	status_text TEXT,                      -- the vendor's own wording
	alert_count INTEGER NOT NULL DEFAULT 0,
	alert_impact INTEGER,                  -- highest impact or severity the vendor gives, if any
	last_report_at TEXT,                   -- vendor's last report time as given
	today_date TEXT,                       -- local date today_wh belongs to
	today_wh REAL,
	week_wh REAL,                          -- vendor-supplied totals where the vendor has them;
	month_wh REAL,                         -- otherwise the page sums monitor_daily
	year_wh REAL,
	lifetime_wh REAL,
	project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
	ignored INTEGER NOT NULL DEFAULT 0,
	first_seen_at TEXT NOT NULL,
	updated_at TEXT NOT NULL,              -- last time the collector refreshed this site (UTC)
	energy_through TEXT,                   -- last local date with stored daily production
	UNIQUE (vendor, vendor_site_id)
);

CREATE TABLE monitor_daily (
	site_id INTEGER NOT NULL REFERENCES monitor_sites(id) ON DELETE CASCADE,
	date TEXT NOT NULL,                    -- local date, YYYY-MM-DD
	wh REAL NOT NULL,
	PRIMARY KEY (site_id, date)
);

CREATE TABLE monitor_runs (
	id INTEGER PRIMARY KEY,
	vendor TEXT NOT NULL,
	started_at TEXT NOT NULL,              -- UTC
	finished_at TEXT,
	calls INTEGER NOT NULL DEFAULT 0,      -- vendor API calls made (credits for SolarEdge)
	ok INTEGER NOT NULL DEFAULT 0,
	message TEXT
);
CREATE INDEX monitor_runs_vendor ON monitor_runs (vendor, started_at);
