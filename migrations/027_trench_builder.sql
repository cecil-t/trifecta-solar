-- Trench builder on build sheets: trench segments (the dig) and the circuits that run through
-- them. The builder sizes wire and conduit and prices the job from catalog parts in the
-- "Trench builder" category, so the old all-in per-foot trench items are no longer needed.

-- Catalog items in the trench_parts category say which part they are:
--   trench_part  dig | bore | mob_trench | mob_bore | inspection | conduit | wire | comms | pull | termination
--   part_size    conduit trade size ("1", "1-1/4") or wire size ("10", "1/0", "250")
--   material     cu | al (wire only)
ALTER TABLE catalog_items ADD COLUMN trench_part TEXT;
ALTER TABLE catalog_items ADD COLUMN part_size TEXT;
ALTER TABLE catalog_items ADD COLUMN material TEXT;

-- A trench segment: one stretch of digging or boring between two points. Circuits refer to
-- segments by key (stable while the form is edited, unlike row numbers).
CREATE TABLE presales_design_trenches (
	id         INTEGER PRIMARY KEY,
	design_id  INTEGER NOT NULL REFERENCES presales_designs(id) ON DELETE CASCADE,
	key        TEXT    NOT NULL,
	name       TEXT,
	method     TEXT    NOT NULL DEFAULT 'open' CHECK (method IN ('open','bore','customer')),
	length_ft  REAL,
	sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_presales_trenches_design ON presales_design_trenches(design_id);

-- A circuit in the ground: AC (by kW and voltage), DC (by string count) or comms.
-- segments = JSON list of trench keys it runs through. size_override needs a reason to count.
CREATE TABLE presales_design_circuits (
	id              INTEGER PRIMARY KEY,
	design_id       INTEGER NOT NULL REFERENCES presales_designs(id) ON DELETE CASCADE,
	key             TEXT    NOT NULL,
	name            TEXT,
	kind            TEXT    NOT NULL DEFAULT 'ac' CHECK (kind IN ('ac','dc','comms')),
	ac_kw           REAL,
	voltage         TEXT,
	strings         INTEGER,
	segments        TEXT,
	size_override   TEXT,
	override_reason TEXT,
	sort_order      INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_presales_circuits_design ON presales_design_circuits(design_id);

-- Trench inspection charged once when there is any open or customer trench (can be unchecked).
-- trench_basis = JSON {"settings": {...}, "prices": {part key: {...}}}: the trench settings and
-- part prices this build sheet was priced with, kept like line snapshots so a saved build sheet
-- keeps its numbers. "Copy at today's prices" starts a fresh basis.
ALTER TABLE presales_designs ADD COLUMN trench_inspection INTEGER NOT NULL DEFAULT 1;
ALTER TABLE presales_designs ADD COLUMN trench_basis TEXT;
