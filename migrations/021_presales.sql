-- Pre-Sales: the price catalog, buildsheet rates, opportunities and their build sheets (designs).
-- Money is stored as REAL dollars here (not cents) because per-watt prices need 4 decimals
-- ($0.1851/W). Totals are rounded only for display, except the final price (up to the next $100).
-- Prices and rates are company data: they arrive through import:presales, never in git.

-- Who may edit the catalog and the buildsheet rates besides admins.
ALTER TABLE users ADD COLUMN is_sales INTEGER NOT NULL DEFAULT 0;

-- Catalog item. One row per product or service, no duplicates for warranty or shipping variants:
--   price            base unit cost to Trifecta (per unit, per watt or per foot, by qty_rule)
--   shipping         added to every unit (per unit, never per watt)
--   setup_cost       added once per line (racking adders, trench start-up)
--   min_total        a line never totals less than this (BOS minimum)
--   qty_rule         manual | dc_watts (system DC watts) | modules (one per module) | module_pairs (one per two modules)
--   tax_class        major (taxed unless the job is tax exempt) | bos (always taxed)
--                    | included (third-party price, tax included) | none (fees, services)
--   ext_warranty_*   optional extended manufacturer warranty the line can switch on
--   siw_cost         SolarInsure cost per unit, charged only when the build sheet uses SolarInsure
--   is_solarinsure   warranty programs: this program is SolarInsure
CREATE TABLE catalog_items (
	id                  INTEGER PRIMARY KEY,
	category            TEXT    NOT NULL,
	name                TEXT    NOT NULL,
	notes               TEXT,
	print_notes         INTEGER NOT NULL DEFAULT 0,
	price               REAL    NOT NULL DEFAULT 0,
	shipping            REAL    NOT NULL DEFAULT 0,
	setup_cost          REAL    NOT NULL DEFAULT 0,
	min_total           REAL,
	qty_rule            TEXT    NOT NULL DEFAULT 'manual' CHECK (qty_rule IN ('manual','dc_watts','modules','module_pairs')),
	tax_class           TEXT    NOT NULL DEFAULT 'bos' CHECK (tax_class IN ('major','bos','included','none')),
	watts               REAL,
	ac_kw               REAL,
	kwh                 REAL,
	kw                  REAL,
	warranty_text       TEXT,
	ext_warranty_years  INTEGER,
	ext_warranty_cost   REAL,
	siw_cost            REAL,
	is_solarinsure      INTEGER NOT NULL DEFAULT 0,
	price_updated_at    TEXT,            -- YYYY-MM-DD the price was last set or confirmed
	price_updated_by    INTEGER REFERENCES users(id),
	price_source        TEXT,            -- e.g. "Distributor quote 4/2026"
	is_active           INTEGER NOT NULL DEFAULT 1,
	sort_order          INTEGER NOT NULL DEFAULT 0,
	created_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	UNIQUE (category, name)
);
CREATE INDEX idx_catalog_items_category ON catalog_items(category, is_active, sort_order);

-- Buildsheet rates: single values (tax rate, labor rate, cap...) as key / value text.
CREATE TABLE presales_settings (
	key        TEXT PRIMARY KEY,
	value      TEXT,
	updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

-- Commission choices. adjusted = the rate actually applied to the marked-up subtotal (the old
-- sheet grosses the rate up, rate / (1 - rate), so it works out to about the rate times the final
-- price); step_down is a flat amount added at that rate.
CREATE TABLE presales_commission_rates (
	id         INTEGER PRIMARY KEY,
	rate       REAL NOT NULL UNIQUE,
	adjusted   REAL NOT NULL,
	step_down  REAL NOT NULL DEFAULT 0,
	is_default INTEGER NOT NULL DEFAULT 0
);

-- Fixed first payment by project class and size. max_kw NULL = no upper limit.
CREATE TABLE presales_deposit_tiers (
	id         INTEGER PRIMARY KEY,
	class      TEXT NOT NULL CHECK (class IN ('residential','commercial')),
	min_kw     REAL NOT NULL DEFAULT 0,
	max_kw     REAL,
	amount     REAL NOT NULL,
	sort_order INTEGER NOT NULL DEFAULT 0
);

-- Payment schedules after the deposit. milestones = JSON [{"label": "...", "pct": 25}, ...] as
-- percent of the balance after the deposit; they must total 100.
CREATE TABLE presales_payment_templates (
	id         INTEGER PRIMARY KEY,
	name       TEXT NOT NULL UNIQUE,
	milestones TEXT NOT NULL,
	is_default INTEGER NOT NULL DEFAULT 0,
	sort_order INTEGER NOT NULL DEFAULT 0
);

-- Contingency rules. trigger: always | ground_work | pole_tracker | difficult_access | manual.
-- Automatic triggers come from the build sheet itself; manual ones are a checkbox on it.
CREATE TABLE presales_contingency_rules (
	id         INTEGER PRIMARY KEY,
	name       TEXT NOT NULL UNIQUE,
	pct        REAL NOT NULL,
	reason     TEXT,
	trigger    TEXT NOT NULL DEFAULT 'manual' CHECK (trigger IN ('always','ground_work','pole_tracker','difficult_access','manual')),
	is_active  INTEGER NOT NULL DEFAULT 1,
	sort_order INTEGER NOT NULL DEFAULT 0
);

-- An opportunity: a customer and a site being quoted. Open, Sold (ties to a project) or Lost.
CREATE TABLE presales_opportunities (
	id              INTEGER PRIMARY KEY,
	name            TEXT    NOT NULL,
	customer_id     INTEGER REFERENCES organizations(id),
	salesperson_id  INTEGER REFERENCES users(id),
	customer_type   TEXT    CHECK (customer_type IN ('residential','commercial','nonprofit','government')),
	is_agricultural INTEGER NOT NULL DEFAULT 0,
	site_street     TEXT,
	site_city       TEXT,
	site_state      TEXT DEFAULT 'PA',
	site_zip        TEXT,
	drive_url       TEXT,
	notes           TEXT,
	status          TEXT    NOT NULL DEFAULT 'open' CHECK (status IN ('open','sold','lost')),
	lost_reason     TEXT,
	sold_design_id  INTEGER,
	project_id      INTEGER REFERENCES projects(id) ON DELETE SET NULL,
	status_at       TEXT,
	created_by      INTEGER REFERENCES users(id),
	created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_presales_opp_status ON presales_opportunities(status, updated_at);
CREATE INDEX idx_presales_opp_customer ON presales_opportunities(customer_id);

-- A build sheet (one design for the opportunity). Rates are copied in when it is created, so a
-- saved build sheet keeps its numbers when the rates change later.
CREATE TABLE presales_designs (
	id                      INTEGER PRIMARY KEY,
	opportunity_id          INTEGER NOT NULL REFERENCES presales_opportunities(id) ON DELETE CASCADE,
	name                    TEXT    NOT NULL,
	install_type            TEXT    CHECK (install_type IN ('roof','ground','tracker')),
	inverter_internet       TEXT,
	tax_exempt              INTEGER NOT NULL DEFAULT 0,
	tax_rate                REAL    NOT NULL DEFAULT 0.06,
	tax_note                TEXT,
	markup                  REAL    NOT NULL DEFAULT 0,
	commission_rate         REAL    NOT NULL DEFAULT 0,
	commission_adjusted     REAL    NOT NULL DEFAULT 0,
	commission_step_down    REAL    NOT NULL DEFAULT 0,
	warranty_item_id        INTEGER REFERENCES catalog_items(id),
	warranty_price          REAL,                -- per watt, copied from the catalog
	warranty_name           TEXT,
	warranty_is_solarinsure INTEGER NOT NULL DEFAULT 0,
	labor_mode              TEXT    NOT NULL DEFAULT 'per_watt' CHECK (labor_mode IN ('per_watt','total')),
	labor_value             REAL    NOT NULL DEFAULT 0,
	labor_rate              REAL    NOT NULL DEFAULT 0,   -- $ per man-hour, for the man-hours figure
	site_visit              REAL    NOT NULL DEFAULT 0,
	overnight               REAL    NOT NULL DEFAULT 0,
	per_diem                REAL    NOT NULL DEFAULT 0,
	contingency_manual      TEXT,                -- JSON list of manual rule ids checked
	contingency_override    REAL,                -- percent typed over the rules, with a reason
	contingency_reason      TEXT,
	contingency_floor       REAL    NOT NULL DEFAULT 0,
	contingency_cap         REAL    NOT NULL DEFAULT 0,
	steep_deg               REAL    NOT NULL DEFAULT 30,
	deposit                 REAL,
	payment_milestones      TEXT,                -- JSON copied from a payment template
	payment_template_name   TEXT,
	notes                   TEXT,
	price_total             REAL,                -- last calculated final price (for lists)
	dc_watts                REAL,
	sort_order              INTEGER NOT NULL DEFAULT 0,
	created_by              INTEGER REFERENCES users(id),
	updated_by              INTEGER REFERENCES users(id),
	created_at              TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at              TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_presales_designs_opp ON presales_designs(opportunity_id, sort_order);

-- Build sheet lines. Catalog lines keep a snapshot of the catalog values when picked, so the
-- original build stays as built; a line differing from today's catalog is flagged.
-- category 'extra' = Extras Without Markup (no markup, no commission, no tax).
CREATE TABLE presales_design_lines (
	id                 INTEGER PRIMARY KEY,
	design_id          INTEGER NOT NULL REFERENCES presales_designs(id) ON DELETE CASCADE,
	category           TEXT    NOT NULL,
	catalog_item_id    INTEGER REFERENCES catalog_items(id) ON DELETE SET NULL,
	name               TEXT    NOT NULL,
	qty                REAL,                -- NULL for automatic quantities
	qty_rule           TEXT    NOT NULL DEFAULT 'manual',
	price              REAL    NOT NULL DEFAULT 0,
	shipping           REAL    NOT NULL DEFAULT 0,
	setup_cost         REAL    NOT NULL DEFAULT 0,
	min_total          REAL,
	tax_class          TEXT    NOT NULL DEFAULT 'bos',
	watts              REAL,
	ac_kw              REAL,
	kwh                REAL,
	kw                 REAL,
	ext_warranty       INTEGER NOT NULL DEFAULT 0,
	ext_warranty_years INTEGER,
	ext_warranty_cost  REAL,
	siw_cost           REAL,
	warranty_text      TEXT,
	price_date         TEXT,                -- the catalog's price date when this line was priced
	note               TEXT,
	sort_order         INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_presales_lines_design ON presales_design_lines(design_id, sort_order);

-- Arrays: roof faces and ground arrays (trackers have no fixed tilt or azimuth).
CREATE TABLE presales_design_arrays (
	id         INTEGER PRIMARY KEY,
	design_id  INTEGER NOT NULL REFERENCES presales_designs(id) ON DELETE CASCADE,
	name       TEXT,
	mount      TEXT CHECK (mount IN ('roof','ground','carport','pole','tracker')),
	material   TEXT,
	azimuth    INTEGER,
	tilt       REAL,
	panels     INTEGER,
	sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_presales_arrays_design ON presales_design_arrays(design_id);
