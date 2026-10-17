-- The picked rate's history from OpenEI, kept with it (JSON: 3 and 5 year yearly change when the
-- data allows, whether unapproved versions are in it, and the generation part now, used as the
-- Price to Compare for the net metering true-up).
ALTER TABLE presales_opportunities ADD COLUMN rate_trend TEXT;

-- Each utility's Price to Compare (cents per kWh), for the true-up when OpenEI gives no generation
-- part for a rate; set on Buildsheet rates, dated when changed.
ALTER TABLE utilities ADD COLUMN price_to_compare REAL;
ALTER TABLE utilities ADD COLUMN price_to_compare_at TEXT;
