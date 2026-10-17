-- An opportunity on a new electric service (new building or meter): no bill yet, so the yearly use
-- in annual_usage_kwh is the expected use, not the last 12 months. The utility and rate still apply.
ALTER TABLE presales_opportunities ADD COLUMN new_service INTEGER NOT NULL DEFAULT 0;
