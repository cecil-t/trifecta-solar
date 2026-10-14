-- Inverters that need an optimizer on every module (SolarEdge). A build sheet with one of these and
-- no optimizer line is flagged. Copied onto build sheet lines like the other catalog values.
ALTER TABLE catalog_items ADD COLUMN needs_mlpe INTEGER NOT NULL DEFAULT 0;
ALTER TABLE presales_design_lines ADD COLUMN needs_mlpe INTEGER NOT NULL DEFAULT 0;
