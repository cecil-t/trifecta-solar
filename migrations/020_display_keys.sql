-- Display links: named keys that open the Monitor page on an office screen without signing in. A key
-- only ever shows that page; it never creates a session. Revoked keys stay listed for the record.

CREATE TABLE display_keys (
	id INTEGER PRIMARY KEY,
	name TEXT NOT NULL,                    -- e.g. "Office TV"
	token TEXT NOT NULL UNIQUE,            -- random, URL-safe; the link is /display?key=<token>
	created_at TEXT NOT NULL,              -- UTC
	created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
	last_used_at TEXT,                     -- UTC, updated at most every 10 minutes
	last_used_ip TEXT,
	revoked_at TEXT                        -- UTC; a revoked key no longer opens anything
);
