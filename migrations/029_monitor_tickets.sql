-- Service tickets opened from a monitoring alert, and the monitored system's site address where the
-- vendor gives one (SolarEdge's site location, SMA's city), for filling in a new ticket. Only the
-- system's location is kept: never the owner's name, email or phone.

ALTER TABLE monitor_sites ADD COLUMN site_street TEXT;
ALTER TABLE monitor_sites ADD COLUMN site_city TEXT;
ALTER TABLE monitor_sites ADD COLUMN site_state TEXT;
ALTER TABLE monitor_sites ADD COLUMN site_zip TEXT;

ALTER TABLE service_tickets ADD COLUMN monitor_site_id INTEGER REFERENCES monitor_sites(id) ON DELETE SET NULL;
CREATE INDEX idx_service_monitor_site ON service_tickets(monitor_site_id);
