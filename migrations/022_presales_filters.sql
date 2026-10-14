-- Pre-Sales round 2.
-- Catalog: which jobs an item is offered on, and domestic content / FEOC for the federal credit.
--   segment   residential | commercial (non-residential) | NULL = both
--   mounts    comma list of install types it fits (roof, ground, tracker); NULL = any
--   domestic_content_pct  share of the item's cost that counts as US content, 0 to 100; NULL = unknown
--   feoc_ok   1 = no prohibited foreign entity content (FEOC compliant), 0 = not, NULL = unknown
ALTER TABLE catalog_items ADD COLUMN segment TEXT CHECK (segment IN ('residential','commercial'));
ALTER TABLE catalog_items ADD COLUMN mounts TEXT;
ALTER TABLE catalog_items ADD COLUMN domestic_content_pct REAL;
ALTER TABLE catalog_items ADD COLUMN feoc_ok INTEGER;

ALTER TABLE presales_design_lines ADD COLUMN domestic_content_pct REAL;
ALTER TABLE presales_design_lines ADD COLUMN feoc_ok INTEGER;

-- Travel: overnight and per diem come from nights away and crew size (stored totals stay in
-- presales_designs.overnight / per_diem so the pricing reads them as before).
ALTER TABLE presales_designs ADD COLUMN trip_nights INTEGER NOT NULL DEFAULT 0;
ALTER TABLE presales_designs ADD COLUMN trip_crew INTEGER NOT NULL DEFAULT 0;
ALTER TABLE presales_designs ADD COLUMN lodging_rate REAL NOT NULL DEFAULT 0;
ALTER TABLE presales_designs ADD COLUMN per_diem_rate REAL NOT NULL DEFAULT 0;
