-- Review providers, simplified: who handles zoning, the building permit, and inspections.
--   *_by: 'municipality' | 'county' | 'third_party' (NULL = not set); *_org_id = the third party
-- Replaces the older *_mode columns (self/agency), which stay in place but are no longer used.
ALTER TABLE projects ADD COLUMN zoning_by TEXT;
ALTER TABLE projects ADD COLUMN building_by TEXT;
ALTER TABLE projects ADD COLUMN building_org_id INTEGER REFERENCES organizations(id);
ALTER TABLE projects ADD COLUMN inspection_by TEXT;
ALTER TABLE municipalities ADD COLUMN zoning_by TEXT;
ALTER TABLE municipalities ADD COLUMN building_by TEXT;
ALTER TABLE municipalities ADD COLUMN building_org_id INTEGER REFERENCES organizations(id);
ALTER TABLE municipalities ADD COLUMN inspection_by TEXT;

UPDATE projects SET
	zoning_by = CASE zoning_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END,
	building_by = CASE plan_review_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END,
	building_org_id = plan_review_org_id,
	inspection_by = CASE inspection_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END;
UPDATE municipalities SET
	zoning_by = CASE zoning_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END,
	building_by = CASE plan_review_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END,
	building_org_id = plan_review_org_id,
	inspection_by = CASE inspection_mode WHEN 'self' THEN 'municipality' WHEN 'agency' THEN 'third_party' END;
