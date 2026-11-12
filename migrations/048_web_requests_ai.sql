-- Quote requests from the company website (a Gravity Forms form), polled by website:poll. Each form
-- entry is kept once (entry_id). It waits as Pending at the top of the Sales list until someone
-- creates an opportunity from it or dismisses it. data_json holds the form's answers, files_json the
-- links to uploaded bills on the website, bill_json what the bill reader found in them.
CREATE TABLE web_requests (
	id             INTEGER PRIMARY KEY,
	source         TEXT    NOT NULL DEFAULT 'gravity_forms',
	form_id        INTEGER NOT NULL,
	entry_id       INTEGER NOT NULL,
	submitted_at   TEXT,
	data_json      TEXT    NOT NULL,
	files_json     TEXT,
	bill_json      TEXT,
	bill_error     TEXT,
	bill_read_at   TEXT,
	bill_attempts  INTEGER NOT NULL DEFAULT 0,
	status         TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','created','dismissed')),
	opportunity_id INTEGER REFERENCES presales_opportunities(id) ON DELETE SET NULL,
	handled_by     INTEGER REFERENCES users(id),
	handled_at     TEXT,
	created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	UNIQUE (source, form_id, entry_id)
);
CREATE INDEX idx_web_requests_status ON web_requests(status, submitted_at);

-- One row per website:poll run, for the schedule (weekends run once) and the status pages.
CREATE TABLE website_polls (
	id          INTEGER PRIMARY KEY,
	started_at  TEXT    NOT NULL,
	finished_at TEXT,
	ok          INTEGER NOT NULL DEFAULT 0,
	forced      INTEGER NOT NULL DEFAULT 0,
	new_count   INTEGER NOT NULL DEFAULT 0,
	error       TEXT
);

-- One row per Claude API call: what it was for, the model, tokens and cost, so real costs show
-- on Lists > Website and AI. The documents sent are never stored.
CREATE TABLE ai_calls (
	id            INTEGER PRIMARY KEY,
	purpose       TEXT    NOT NULL,
	model         TEXT    NOT NULL,
	input_tokens  INTEGER NOT NULL DEFAULT 0,
	output_tokens INTEGER NOT NULL DEFAULT 0,
	cost_usd      REAL    NOT NULL DEFAULT 0,
	ms            INTEGER NOT NULL DEFAULT 0,
	ok            INTEGER NOT NULL DEFAULT 0,
	error         TEXT,
	user_id       INTEGER REFERENCES users(id),
	created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_ai_calls_created ON ai_calls(created_at);
