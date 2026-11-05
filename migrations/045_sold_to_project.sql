-- A sold build sheet becomes a project (App\SoldProject).
-- The project remembers the build sheet it came from, and that build sheet's monthly production
-- estimate (JSON list of 12 kWh values, January first) for the production check.
ALTER TABLE projects ADD COLUMN design_id INTEGER REFERENCES presales_designs(id) ON DELETE SET NULL;
ALTER TABLE projects ADD COLUMN est_monthly_kwh TEXT;

-- Project arrays (formerly roof faces) carry what the build sheet knows about each array.
-- mount: keys in Presales::MOUNTS (NULL reads as roof, since every earlier row was a roof face).
-- Area is the panels' own surface and perimeter the tape measure around the array, from the
-- site layout (App\Layout::arrayStats).
ALTER TABLE project_roofs ADD COLUMN mount TEXT;
ALTER TABLE project_roofs ADD COLUMN shading_pct REAL;
ALTER TABLE project_roofs ADD COLUMN area_m2 REAL;
ALTER TABLE project_roofs ADD COLUMN perimeter_m REAL;
CREATE INDEX idx_projects_design ON projects(design_id);
