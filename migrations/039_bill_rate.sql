-- The rate from the customer's electric bill, typed by the salesperson, and a typed Price to Compare.
-- bill_charge: customer charge, $ a month. bill_total: the bill (or bills) in $. bill_kwh: kWh on them.
-- bill_months: how many months the total and kWh cover (1 to 12). Retail rate = (total - charge x months) / kWh.
-- ptc_cents: Price to Compare for the May true-up, cents per kWh; wins over the picked rate and the Rates page.
ALTER TABLE presales_opportunities ADD COLUMN bill_charge REAL;
ALTER TABLE presales_opportunities ADD COLUMN bill_total REAL;
ALTER TABLE presales_opportunities ADD COLUMN bill_kwh REAL;
ALTER TABLE presales_opportunities ADD COLUMN bill_months INTEGER;
ALTER TABLE presales_opportunities ADD COLUMN ptc_cents REAL;
