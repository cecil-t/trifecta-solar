-- Draws against commission. A salesperson on a draw is paid a set amount every payroll; the draws
-- cover his commission as jobs come due, and a true-up squares the two now and then.
--
-- Advance = draws paid - commission covered by draws. Positive: he has been paid ahead of what has
-- come due (carried forward until his jobs cover it, and taken out of what is owed if he leaves).
-- Negative is not stored: commission due beyond the draws shows as an owed balance on the jobs.

-- A schedule writes one draw line per payroll date (every every_days days from start_date) up to
-- today, and up to end_date when the draws stop. generated_through is the last date written, so a
-- line deleted by hand (a skipped payroll) is not written again.
CREATE TABLE commission_draw_schedules (
	id                INTEGER PRIMARY KEY,
	user_id           INTEGER NOT NULL REFERENCES users(id),
	amount            REAL    NOT NULL,
	start_date        TEXT    NOT NULL,
	every_days        INTEGER NOT NULL DEFAULT 14,
	end_date          TEXT,
	generated_through TEXT,
	note              TEXT,
	created_by        INTEGER REFERENCES users(id),
	created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_draw_schedules_user ON commission_draw_schedules(user_id);

-- Money paid on a draw. kind 'opening' is a starting advance (draws already paid and not yet
-- covered by commission) as of pay_date, for a salesperson whose draws began before the tracker.
-- edited marks a scheduled line changed by hand, so a schedule change leaves it alone.
CREATE TABLE commission_draws (
	id          INTEGER PRIMARY KEY,
	user_id     INTEGER NOT NULL REFERENCES users(id),
	schedule_id INTEGER REFERENCES commission_draw_schedules(id) ON DELETE SET NULL,
	pay_date    TEXT    NOT NULL,
	amount      REAL    NOT NULL,
	kind        TEXT    NOT NULL DEFAULT 'draw' CHECK (kind IN ('draw','opening')),
	edited      INTEGER NOT NULL DEFAULT 0,
	note        TEXT,
	created_by  INTEGER REFERENCES users(id),
	created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_draws_user ON commission_draws(user_id, pay_date);
CREATE UNIQUE INDEX idx_draws_schedule_date ON commission_draws(schedule_id, pay_date) WHERE schedule_id IS NOT NULL;

-- A commission payment covered by draws (source 'draw') rather than paid in cash (NULL).
ALTER TABLE commission_entries ADD COLUMN source TEXT;

