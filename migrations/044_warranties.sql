-- Warranties. The Trifecta 10 year and SolarInsure 30 year lines are not stored: they follow the
-- project's warranty program (warranty_program, or when blank the SolarInsure registration
-- answer), and the SolarEdge extended line follows that closeout question. project_warranties
-- holds every other line: manufacturer product and performance warranties, extended warranties
-- not asked about by a question, and production guarantees.
--
-- warranty_program: trifecta, solarinsure, none (not our install, e.g. a takeover), unknown
-- (needs review); NULL = follow the SolarInsure registration question.
-- warranty_start: typed start date for the program lines, over the date worked out from the steps.
-- is_legacy: a bare-minimum record for a system installed before the tracker existed.
ALTER TABLE projects ADD COLUMN warranty_program TEXT CHECK (warranty_program IN ('trifecta', 'solarinsure', 'none', 'unknown'));
ALTER TABLE projects ADD COLUMN warranty_start TEXT;
ALTER TABLE projects ADD COLUMN is_legacy INTEGER NOT NULL DEFAULT 0;

CREATE TABLE project_warranties (
	id              INTEGER PRIMARY KEY,
	project_id      INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	kind            TEXT    NOT NULL CHECK (kind IN ('module_product', 'module_performance', 'inverter', 'inverter_extended',
	                        'mlpe', 'battery', 'racking', 'production_guarantee', 'other')),
	provider        TEXT,             -- manufacturer or who stands behind it, e.g. "SEG Solar"
	covers          TEXT,             -- what it covers, e.g. "SEG-590-BTA-BG modules" or "87.4% at 30 years"
	term_years      REAL    NOT NULL,
	start_basis     TEXT    NOT NULL DEFAULT 'install' CHECK (start_basis IN ('inspection', 'pto', 'install', 'contract')),
	start_date      TEXT,             -- typed date, over the date worked out from start_basis
	cost_cents      INTEGER,          -- only for warranties Trifecta pays for (an extended warranty); NULL otherwise
	reference       TEXT,             -- registration or certificate number
	guarantee_kwh   REAL,             -- production guarantee: guaranteed kWh over the term
	guarantee_rate  REAL,             -- production guarantee: payout $ per kWh short
	note            TEXT,
	source          TEXT    NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'import', 'build_sheet')),
	created_by      INTEGER REFERENCES users(id),
	created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
	updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_project_warranties_project ON project_warranties (project_id);
