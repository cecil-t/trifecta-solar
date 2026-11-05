-- Internal cost per DC watt for Trifecta's 10 year warranty behind SolarInsure (what SolarInsure
-- doesn't cover: wiring and balance of system). Copied from the Sales rates setting when a build
-- sheet picks a SolarInsure program; NULL on older build sheets (no adder), so saved quotes keep
-- their price.
ALTER TABLE presales_designs ADD COLUMN warranty_adder REAL;
