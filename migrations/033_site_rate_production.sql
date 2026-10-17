-- Spec-sheet values for catalog modules and inverters that SAM's CEC library doesn't list (JSON:
-- modules Vmp, Imp, Voc, Isc, temperature coefficients, cells, NOCT, size; inverters AC rating,
-- CEC efficiency, DC voltages). Build sheet lines keep them like the other catalog values.
ALTER TABLE catalog_items ADD COLUMN spec TEXT;
ALTER TABLE presales_design_lines ADD COLUMN spec TEXT;

-- The opportunity's site: municipality (as on projects), the map point SAM and OpenEI use, and
-- where the point came from. geo_address is the address the point was found for, so a changed
-- address is looked up again.
ALTER TABLE presales_opportunities ADD COLUMN municipality_id INTEGER REFERENCES municipalities(id);
ALTER TABLE presales_opportunities ADD COLUMN site_lat REAL;
ALTER TABLE presales_opportunities ADD COLUMN site_lon REAL;
ALTER TABLE presales_opportunities ADD COLUMN geo_source TEXT;     -- google, census, osm, manual
ALTER TABLE presales_opportunities ADD COLUMN geo_address TEXT;

-- The utility rate picked from OpenEI's Utility Rate Database: its label (URDB id), name, the
-- utility as OpenEI names it, and the full rate as it was when picked (what bill math will use).
ALTER TABLE presales_opportunities ADD COLUMN rate_label TEXT;
ALTER TABLE presales_opportunities ADD COLUMN rate_name TEXT;
ALTER TABLE presales_opportunities ADD COLUMN rate_utility TEXT;
ALTER TABLE presales_opportunities ADD COLUMN rate_json TEXT;
ALTER TABLE presales_opportunities ADD COLUMN rate_fetched_at TEXT;

-- A build sheet's production estimate: the reply from SAM (detailed model and PVWatts v8, year one
-- by month), when it ran, and a key of the inputs so a changed build sheet shows it is out of date.
ALTER TABLE presales_designs ADD COLUMN production_json TEXT;
ALTER TABLE presales_designs ADD COLUMN production_at TEXT;
ALTER TABLE presales_designs ADD COLUMN production_key TEXT;
