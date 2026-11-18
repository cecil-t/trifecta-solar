-- migrator: foreign_keys off
-- Service coverage gains 'closeout': a truck roll that finishes an install after inspection.
-- Not billed, not workmanship, not a third-party warranty.
-- SQLite can't change a CHECK constraint in place, so service_tickets is rebuilt (SQLite's
-- documented create / copy / drop / rename procedure). The Migrator turns foreign keys off for
-- this file, so service_visits (ON DELETE CASCADE) and todos (SET NULL) keep their rows.

CREATE TABLE service_tickets_new (
	id              INTEGER PRIMARY KEY,
	ticket_number   TEXT NOT NULL UNIQUE,              -- S26001
	opened_on       TEXT NOT NULL,                     -- YYYY-MM-DD
	customer_id     INTEGER REFERENCES organizations(id),
	project_id      INTEGER REFERENCES projects(id),   -- the original install, when it's ours
	site_street     TEXT,
	site_city       TEXT,
	site_state      TEXT,
	site_zip        TEXT,
	description     TEXT NOT NULL,
	source          TEXT CHECK (source IN ('customer', 'monitoring', 'trifecta', 'other')),
	trifecta_install INTEGER CHECK (trifecta_install IN (0, 1)),
	coverage        TEXT CHECK (coverage IN ('billable', 'warranty', 'solarinsure', 'closeout')),
	owner_id        INTEGER REFERENCES users(id),
	scheduled_on    TEXT,                              -- next planned visit
	completed_on    TEXT,
	drive_url       TEXT,
	billing_note    TEXT,
	bill_amount_cents INTEGER,                         -- what was billed (customer or SolarInsure)
	invoiced        INTEGER NOT NULL DEFAULT 0 CHECK (invoiced IN (0, 1)),
	invoiced_on     TEXT,
	invoice_number  TEXT,
	created_by      INTEGER REFERENCES users(id),
	created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
	updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
	monitoring_url  TEXT,
	monitor_site_id INTEGER REFERENCES monitor_sites(id) ON DELETE SET NULL
);

INSERT INTO service_tickets_new (
	id, ticket_number, opened_on, customer_id, project_id, site_street, site_city, site_state, site_zip,
	description, source, trifecta_install, coverage, owner_id, scheduled_on, completed_on, drive_url,
	billing_note, bill_amount_cents, invoiced, invoiced_on, invoice_number, created_by, created_at,
	updated_at, monitoring_url, monitor_site_id
)
SELECT
	id, ticket_number, opened_on, customer_id, project_id, site_street, site_city, site_state, site_zip,
	description, source, trifecta_install, coverage, owner_id, scheduled_on, completed_on, drive_url,
	billing_note, bill_amount_cents, invoiced, invoiced_on, invoice_number, created_by, created_at,
	updated_at, monitoring_url, monitor_site_id
FROM service_tickets;

DROP TABLE service_tickets;
ALTER TABLE service_tickets_new RENAME TO service_tickets;

CREATE INDEX idx_service_customer ON service_tickets(customer_id);
CREATE INDEX idx_service_project ON service_tickets(project_id);
CREATE INDEX idx_service_monitor_site ON service_tickets(monitor_site_id);
