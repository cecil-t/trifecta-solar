-- Site layout: panels drawn on the aerial view. A build sheet keeps its drawing as JSON (the map
-- origin, the module size used, each array's position, rotation, pitch and panels, measurements and
-- Google's roof faces). Arrays made by the drawing carry its key, so saving the drawing updates
-- those rows and leaves typed ones (trackers, jobs drawn without imagery) alone.
ALTER TABLE presales_designs ADD COLUMN layout_json TEXT;
ALTER TABLE presales_designs ADD COLUMN layout_at TEXT;
ALTER TABLE presales_designs ADD COLUMN layout_by INTEGER REFERENCES users(id);
ALTER TABLE presales_design_arrays ADD COLUMN layout_key TEXT;

-- Module size for drawing panels (millimeters, from the spec sheet). Build sheet lines keep it like
-- the other catalog values. Spec-sheet items already have it in their spec values.
ALTER TABLE catalog_items ADD COLUMN length_mm REAL;
ALTER TABLE catalog_items ADD COLUMN width_mm REAL;
ALTER TABLE presales_design_lines ADD COLUMN length_mm REAL;
ALTER TABLE presales_design_lines ADD COLUMN width_mm REAL;

UPDATE catalog_items SET length_mm = json_extract(spec, '$.length_mm'), width_mm = json_extract(spec, '$.width_mm')
	WHERE category = 'modules' AND spec IS NOT NULL AND json_valid(spec) AND length_mm IS NULL;
UPDATE presales_design_lines SET length_mm = json_extract(spec, '$.length_mm'), width_mm = json_extract(spec, '$.width_mm')
	WHERE category = 'modules' AND spec IS NOT NULL AND json_valid(spec) AND length_mm IS NULL;
