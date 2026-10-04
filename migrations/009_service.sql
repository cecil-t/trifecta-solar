-- Service tickets: a request (customer call or something spotted in a monitoring portal),
-- the visits made for it, and the billing outcome Staff needs for QuickBooks.
-- No rates are stored: the ticket records what was billed, not how it was priced.

CREATE TABLE service_tickets (
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
	coverage        TEXT CHECK (coverage IN ('billable', 'warranty', 'solarinsure')),
	owner_id        INTEGER REFERENCES users(id),
	scheduled_on    TEXT,                              -- next planned visit
	completed_on    TEXT,
	drive_url       TEXT,
	billing_note    TEXT,                              -- e.g. "Quoted $4,700 for the upgrade, trip free"
	bill_amount_cents INTEGER,                         -- what was billed (customer or SolarInsure)
	invoiced        INTEGER NOT NULL DEFAULT 0 CHECK (invoiced IN (0, 1)),
	invoiced_on     TEXT,
	invoice_number  TEXT,
	created_by      INTEGER REFERENCES users(id),
	created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
	updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_service_customer ON service_tickets(customer_id);
CREATE INDEX idx_service_project ON service_tickets(project_id);

CREATE TABLE service_visits (
	id          INTEGER PRIMARY KEY,
	ticket_id   INTEGER NOT NULL REFERENCES service_tickets(id) ON DELETE CASCADE,
	visit_date  TEXT,
	crew        TEXT,                                  -- free text: "Staff, Crew Member"
	man_hours   REAL,                                  -- on-site man-hours, travel not included
	trips       INTEGER NOT NULL DEFAULT 1,
	note        TEXT,
	created_by  INTEGER REFERENCES users(id),
	created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
	updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_visits_ticket ON service_visits(ticket_id);
