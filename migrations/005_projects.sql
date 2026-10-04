-- Projects, their equipment lines, funding, and the task system (template + per-project copies).

CREATE TABLE projects (
	id                  INTEGER PRIMARY KEY,
	project_number      TEXT    NOT NULL UNIQUE,              -- e.g. 26035
	name                TEXT    NOT NULL UNIQUE COLLATE NOCASE,
	customer_id         INTEGER REFERENCES organizations(id),
	salesperson_id      INTEGER REFERENCES users(id),
	customer_type       TEXT    CHECK (customer_type IN ('residential','commercial','nonprofit','government')),
	is_agricultural     INTEGER NOT NULL DEFAULT 0,
	install_type        TEXT    CHECK (install_type IN ('roof','ground','tracker')),
	racking             TEXT,                                 -- e.g. SunAction 48, Mechatron, IronRidge XR100
	has_pv              INTEGER NOT NULL DEFAULT 1,
	has_batteries       INTEGER NOT NULL DEFAULT 0,
	tax_exempt          INTEGER,                              -- NULL = unknown
	contract_price_cents INTEGER,
	est_annual_kwh      INTEGER,
	funding_note        TEXT,

	site_street         TEXT,
	site_city           TEXT,
	site_state          TEXT DEFAULT 'PA',
	site_zip            TEXT,

	utility_id          INTEGER REFERENCES utilities(id),
	municipality_id     INTEGER REFERENCES municipalities(id),
	zoning_mode         TEXT CHECK (zoning_mode IN ('self','agency')),
	zoning_org_id       INTEGER REFERENCES organizations(id),
	plan_review_mode    TEXT CHECK (plan_review_mode IN ('self','agency')),
	plan_review_org_id  INTEGER REFERENCES organizations(id),
	inspection_mode     TEXT CHECK (inspection_mode IN ('self','agency')),
	inspection_org_id   INTEGER REFERENCES organizations(id),
	designer_org_id     INTEGER REFERENCES organizations(id),
	installer_org_id    INTEGER REFERENCES organizations(id), -- NULL = Trifecta in-house

	drive_url           TEXT,
	status_note         TEXT,                                 -- free-text "where it stands"
	hold_state          TEXT CHECK (hold_state IN ('on_hold','cancelled')),
	hold_reason         TEXT,

	created_by          INTEGER REFERENCES users(id),
	created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_projects_customer ON projects(customer_id);
CREATE INDEX idx_projects_salesperson ON projects(salesperson_id);

CREATE TABLE project_contacts (
	project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
	PRIMARY KEY (project_id, contact_id)
);

-- Equipment lines. DC kW = sum(qty * watts) / 1000; AC kW = sum(qty * ac_kw); storage = sum(qty * kwh).
CREATE TABLE project_modules (
	id          INTEGER PRIMARY KEY,
	project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	qty         INTEGER NOT NULL,
	watts       REAL    NOT NULL,
	description TEXT,
	sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE project_inverters (
	id          INTEGER PRIMARY KEY,
	project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	qty         INTEGER NOT NULL,
	ac_kw       REAL    NOT NULL,
	description TEXT,
	sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE project_batteries (
	id          INTEGER PRIMARY KEY,
	project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	qty         INTEGER NOT NULL,
	kwh         REAL,
	kw          REAL,
	description TEXT,
	sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE project_funding (
	project_id        INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	funding_source_id INTEGER NOT NULL REFERENCES funding_sources(id),
	PRIMARY KEY (project_id, funding_source_id)
);

-- ---------------------------------------------------------------------------
-- Task template (admin-editable). Two levels: a task (parent_id NULL) and its sub-tasks.
-- phase:        pre_install | installation | closeout | payments  (grouping only; order is never enforced)
-- default_needed: NULL (unanswered) / 1 yes / 0 no
-- applies_when: all | pv | storage  (which new projects receive it)
-- gate:         start_clock      - its done date is the contract-signed date (day counter starts)
--               install_prereq   - required for "Clear to install"; within one task, ANY prereq sub-task
--                                  satisfies it (e.g. conditional OR full interconnection approval)
--               install_started  - its done date moves the project to Installation
--               pto              - its done date moves the project to Closeout (day counter stops)
-- ref_label:    label for the reference # field (Permit #, Work order #); NULL = no reference field
CREATE TABLE task_templates (
	id               INTEGER PRIMARY KEY,
	parent_id        INTEGER REFERENCES task_templates(id) ON DELETE CASCADE,
	name             TEXT    NOT NULL,
	phase            TEXT    NOT NULL CHECK (phase IN ('pre_install','installation','closeout','payments')),
	sort_order       INTEGER NOT NULL DEFAULT 0,
	default_needed   INTEGER,
	applies_when     TEXT    NOT NULL DEFAULT 'all' CHECK (applies_when IN ('all','pv','storage')),
	gate             TEXT    CHECK (gate IN ('start_clock','install_prereq','install_started','pto')),
	ref_label        TEXT,
	default_owner_id INTEGER REFERENCES users(id),
	is_active        INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_task_templates_parent ON task_templates(parent_id, sort_order);

-- Per-project copy. Freely editable per job; custom tasks have template_id NULL.
CREATE TABLE project_tasks (
	id              INTEGER PRIMARY KEY,
	project_id      INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	parent_id       INTEGER REFERENCES project_tasks(id) ON DELETE CASCADE,
	template_id     INTEGER REFERENCES task_templates(id) ON DELETE SET NULL,
	name            TEXT    NOT NULL,
	phase           TEXT    NOT NULL CHECK (phase IN ('pre_install','installation','closeout','payments')),
	sort_order      INTEGER NOT NULL DEFAULT 0,
	needed          INTEGER,
	gate            TEXT    CHECK (gate IN ('start_clock','install_prereq','install_started','pto')),
	ref_label       TEXT,
	reference       TEXT,
	owner_id        INTEGER REFERENCES users(id),
	target_date     TEXT,            -- YYYY-MM-DD: scheduled / promised / expected
	done_date       TEXT,            -- YYYY-MM-DD: actually happened
	note            TEXT,
	note_updated_by INTEGER REFERENCES users(id),
	note_updated_at TEXT,
	created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_project_tasks_project ON project_tasks(project_id, parent_id, sort_order);
CREATE INDEX idx_project_tasks_owner ON project_tasks(owner_id);
