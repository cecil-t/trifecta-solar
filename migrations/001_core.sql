-- Core: users, persistent device logins, login throttling, and the unified activity log.
-- All timestamps are stored as UTC ISO-8601 strings (e.g. 2026-10-02T23:15:00Z).

CREATE TABLE users (
	id                  INTEGER PRIMARY KEY,
	email               TEXT    NOT NULL UNIQUE COLLATE NOCASE,
	name                TEXT    NOT NULL,
	initials            TEXT,
	password_hash       TEXT,                       -- NULL until a password is set
	is_admin            INTEGER NOT NULL DEFAULT 0,
	is_active           INTEGER NOT NULL DEFAULT 1,
	password_changed_at TEXT,
	created_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

-- One row per logged-in device. The cookie holds a random token; only its SHA-256 hash is stored.
-- Revoking a row signs that device out without touching the user's password.
CREATE TABLE device_sessions (
	id           INTEGER PRIMARY KEY,
	user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	token_hash   TEXT    NOT NULL UNIQUE,
	label        TEXT,
	user_agent   TEXT,
	created_ip   TEXT,
	last_ip      TEXT,
	created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	last_seen_at TEXT,
	revoked_at   TEXT
);
CREATE INDEX idx_device_sessions_user ON device_sessions(user_id);

CREATE TABLE login_attempts (
	id         INTEGER PRIMARY KEY,
	email      TEXT,
	ip         TEXT,
	success    INTEGER NOT NULL,
	created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_login_attempts_email ON login_attempts(email, created_at);
CREATE INDEX idx_login_attempts_ip    ON login_attempts(ip, created_at);

-- Unified log for every record type (project, service ticket, user, ...):
--   kind = 'comment' : typed by a person; editable/deletable by its author for 7 days
--   kind = 'change'  : written by the system when a tracked field changes (field / old / new)
--   kind = 'event'   : written by the system for actions (created, password reset, ...)
CREATE TABLE activity_log (
	id          INTEGER PRIMARY KEY,
	entity_type TEXT    NOT NULL,
	entity_id   INTEGER NOT NULL,
	kind        TEXT    NOT NULL CHECK (kind IN ('comment','change','event')),
	user_id     INTEGER REFERENCES users(id),
	field       TEXT,
	old_value   TEXT,
	new_value   TEXT,
	body        TEXT,
	created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	edited_at   TEXT,
	deleted_at  TEXT
);
CREATE INDEX idx_activity_entity ON activity_log(entity_type, entity_id, created_at);
CREATE INDEX idx_activity_user   ON activity_log(user_id, created_at);
