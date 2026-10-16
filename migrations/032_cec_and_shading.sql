-- Production modeling inputs. Catalog modules, inverters and microinverters name their model in
-- SAM's CEC libraries (the name exactly as the library lists it), so a build sheet can run SAM and
-- PVWatts on its real equipment. cec_units: how many library units make one catalog inverter (the
-- largest SolarEdge commercial inverters are listed as the 16.5 or 33.3 kW units they are built
-- from). Module degradation and warranty years are for proposal projections.
ALTER TABLE catalog_items ADD COLUMN cec_name TEXT;
ALTER TABLE catalog_items ADD COLUMN cec_units INTEGER;
ALTER TABLE catalog_items ADD COLUMN degradation_first_pct REAL;    -- first-year loss, percent
ALTER TABLE catalog_items ADD COLUMN degradation_annual_pct REAL;   -- each later year, percent of the first year's output
ALTER TABLE catalog_items ADD COLUMN product_warranty_years INTEGER;
ALTER TABLE catalog_items ADD COLUMN performance_warranty_years INTEGER;

-- Build sheet lines keep these as they were when the line was priced, like the other catalog values.
ALTER TABLE presales_design_lines ADD COLUMN cec_name TEXT;
ALTER TABLE presales_design_lines ADD COLUMN cec_units INTEGER;
ALTER TABLE presales_design_lines ADD COLUMN degradation_first_pct REAL;
ALTER TABLE presales_design_lines ADD COLUMN degradation_annual_pct REAL;
ALTER TABLE presales_design_lines ADD COLUMN product_warranty_years INTEGER;
ALTER TABLE presales_design_lines ADD COLUMN performance_warranty_years INTEGER;

-- Shading per array: an annual loss percent (0 = unshaded) and how it was judged.
ALTER TABLE presales_design_arrays ADD COLUMN shading_pct REAL;
ALTER TABLE presales_design_arrays ADD COLUMN shading_note TEXT;
