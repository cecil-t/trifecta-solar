-- Newer versions of what the app is built on (SAM, PySAM, WeasyPrint, pillow-heif, NSRDB weather,
-- the Claude model, PHP), found by updates:check. One row per item; "Hide until the next version"
-- keeps the hidden version in dismissed_version so the flag comes back only for a newer one.
CREATE TABLE update_checks (
	item              TEXT PRIMARY KEY,
	current_version   TEXT,
	latest_version    TEXT,
	level             TEXT NOT NULL DEFAULT 'ok' CHECK (level IN ('ok','minor','major','alert','error')),
	note              TEXT,
	url               TEXT,
	dismissed_version TEXT,
	checked_at        TEXT NOT NULL
);
