-- General project notes: one free-text block on the project page, with who saved it last and when
ALTER TABLE projects ADD COLUMN notes TEXT;
ALTER TABLE projects ADD COLUMN notes_updated_by INTEGER REFERENCES users(id);
ALTER TABLE projects ADD COLUMN notes_updated_at TEXT;

-- Roof faces for rooftop installs: one row per face or building, with its share of the panels
CREATE TABLE project_roofs (
	id          INTEGER PRIMARY KEY,
	project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	name        TEXT,            -- e.g. "East face", "Bank barn"
	material    TEXT,            -- keys in Projects::ROOF_MATERIALS
	azimuth     INTEGER,         -- degrees from true north, 0 to 359 (180 = due south)
	tilt        REAL,            -- degrees from horizontal
	panels      INTEGER,         -- panels on this face
	sort_order  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_project_roofs_project ON project_roofs(project_id);
