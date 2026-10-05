-- Census township / county lookup for each project's site address, and lookups cached by address
ALTER TABLE projects ADD COLUMN geo_address TEXT;      -- the address that was looked up
ALTER TABLE projects ADD COLUMN geo_checked_at TEXT;
ALTER TABLE projects ADD COLUMN geo_status TEXT;       -- found / no_match
ALTER TABLE projects ADD COLUMN geo_muni TEXT;         -- Census name, e.g. "Penn township"; NULL outside a municipality (MD, DE)
ALTER TABLE projects ADD COLUMN geo_county TEXT;       -- e.g. "Lancaster"
ALTER TABLE projects ADD COLUMN geo_state TEXT;
ALTER TABLE projects ADD COLUMN geo_lat REAL;
ALTER TABLE projects ADD COLUMN geo_lon REAL;
ALTER TABLE projects ADD COLUMN geo_source TEXT;       -- keys in App\Geo::SOURCES
ALTER TABLE projects ADD COLUMN muni_confirmed_id INTEGER REFERENCES municipalities(id); -- kept over a lookup that disagrees

CREATE TABLE geo_cache (
	address_key TEXT PRIMARY KEY,
	result      TEXT NOT NULL,     -- JSON from App\Geo::lookup
	fetched_at  TEXT NOT NULL
);
