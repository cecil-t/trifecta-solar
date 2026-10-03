-- Archive a finished project manually (hides it from Active), and keep the OpenSolar quote #.
ALTER TABLE projects ADD COLUMN archived_at TEXT;
ALTER TABLE projects ADD COLUMN quote_number TEXT;
