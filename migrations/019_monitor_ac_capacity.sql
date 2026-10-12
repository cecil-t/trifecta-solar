-- Inverter AC rating per monitored system, for vendors that report AC size but not the array's DC size
-- (Growatt: the sum of the inverters' maximum output). Kept apart from capacity_kw (DC) so the page
-- never mixes the two silently.
ALTER TABLE monitor_sites ADD COLUMN capacity_ac_kw REAL;
