-- Reference jobs for checking production estimates: a built, monitored system with what its signed
-- proposal estimated, so SAM's detailed model and PVWatts v8 can be run on the same inputs and both
-- compared with the proposal and with real monitored production, month by month. Admin test bench
-- (Lists > Proposal APIs > Reference jobs); added by import:reference-jobs.

CREATE TABLE production_refs (
	id               INTEGER PRIMARY KEY,
	name             TEXT    NOT NULL UNIQUE,
	project_id       INTEGER REFERENCES projects(id) ON DELETE SET NULL,
	monitor_site_id  INTEGER REFERENCES monitor_sites(id) ON DELETE SET NULL,
	in_service       TEXT,              -- date the system started producing (months before it, and its own month unless the 1st, are not compared)
	lat              REAL    NOT NULL,
	lon              REAL    NOT NULL,
	module           TEXT    NOT NULL,  -- SAM CEC module library name
	inverter         TEXT    NOT NULL,  -- SAM CEC inverter library name
	inverter_count   INTEGER NOT NULL DEFAULT 1,
	optimizers       INTEGER NOT NULL DEFAULT 0,
	equipment        TEXT,              -- as the proposal lists it, for reading
	arrays           TEXT    NOT NULL,  -- JSON [{azimuth, tilt, modules, mount}]
	loss_total       REAL,              -- percent, as the proposal states them
	loss_inverter    REAL,
	loss_optimizer   REAL,
	loss_shading     REAL,
	proposal_tool    TEXT,              -- e.g. OpenSolar, PVWatts v8
	proposal_annual  REAL,              -- kWh
	proposal_monthly TEXT,              -- JSON, 12 kWh values Jan to Dec
	notes            TEXT,
	last_result      TEXT,              -- JSON reply of the last run (SAM and PVWatts, weather file used)
	last_run_at      TEXT,
	created_at       TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at       TEXT
);
