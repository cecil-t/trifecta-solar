-- Utility bill details, captured from the customer's electric bill at the first sales visit and
-- carried into the project: name on the bill, account and meter numbers, rate (optional), the
-- service address when it differs from the site, and the last 12 months of usage in kWh.
ALTER TABLE presales_opportunities ADD COLUMN utility_id INTEGER REFERENCES utilities(id);
ALTER TABLE presales_opportunities ADD COLUMN bill_name TEXT;
ALTER TABLE presales_opportunities ADD COLUMN utility_account TEXT;
ALTER TABLE presales_opportunities ADD COLUMN utility_meter TEXT;
ALTER TABLE presales_opportunities ADD COLUMN utility_tariff TEXT;
ALTER TABLE presales_opportunities ADD COLUMN service_address TEXT;
ALTER TABLE presales_opportunities ADD COLUMN annual_usage_kwh INTEGER;

ALTER TABLE projects ADD COLUMN bill_name TEXT;
ALTER TABLE projects ADD COLUMN utility_account TEXT;
ALTER TABLE projects ADD COLUMN utility_meter TEXT;
ALTER TABLE projects ADD COLUMN utility_tariff TEXT;
ALTER TABLE projects ADD COLUMN service_address TEXT;
ALTER TABLE projects ADD COLUMN annual_usage_kwh INTEGER;
