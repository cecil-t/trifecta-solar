-- A build sheet line can override its catalog cost per unit. The override only counts with a reason,
-- and both show on the build sheet, in the opportunity log and on the Opportunities report.
ALTER TABLE presales_design_lines ADD COLUMN price_override REAL;
ALTER TABLE presales_design_lines ADD COLUMN override_reason TEXT;
