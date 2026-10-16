-- Weather for SAM production estimates: NSRDB typical-year (TMY) files, downloaded once per NSRDB
-- grid cell (about 2 km) and kept in data/solar/<dataset>/<location id>.csv. Nearby sites share a
-- cell's file. Lookups remember which cell a requested point fell in, so a repeat needs no call.

CREATE TABLE solar_resource_files (
	id          INTEGER PRIMARY KEY,
	dataset     TEXT    NOT NULL,   -- NSRDB name, e.g. tmy-2024
	location_id TEXT    NOT NULL,   -- NSRDB Location ID (the grid cell)
	lat         REAL,               -- the cell's point, from the file
	lon         REAL,
	elevation   REAL,               -- m
	tz          REAL,               -- hours from UTC (local standard time)
	file        TEXT    NOT NULL,   -- path under data/
	bytes       INTEGER,
	fetched_at  TEXT    NOT NULL,
	UNIQUE (dataset, location_id)
);

-- A requested point (rounded to 0.001 degree, about 100 m) and the cell NSRDB gave for it.
CREATE TABLE solar_resource_lookups (
	dataset     TEXT NOT NULL,
	lat_key     TEXT NOT NULL,
	lon_key     TEXT NOT NULL,
	location_id TEXT NOT NULL,
	PRIMARY KEY (dataset, lat_key, lon_key)
);
