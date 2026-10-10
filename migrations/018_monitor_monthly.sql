-- Monthly production per monitored system, filled once by monitor:backfill for months before daily
-- tracking began. The monitoring page counts a month from monitor_daily when it has days for it, and
-- from here otherwise, so a month is never counted twice.
CREATE TABLE monitor_monthly (
	site_id INTEGER NOT NULL REFERENCES monitor_sites(id) ON DELETE CASCADE,
	month TEXT NOT NULL,                   -- YYYY-MM, local
	wh REAL NOT NULL,
	PRIMARY KEY (site_id, month)
);
