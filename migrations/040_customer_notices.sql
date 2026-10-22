-- Customer updates: template items can queue a "notify the customer" line on a project when
-- they happen. Lines collect on the project until someone marks them notified (or not).
-- notify_on: complete = a task is complete (every sub-task completed or not needed, at least one
-- completed); done = this item gets a completed date; target = this item gets or changes a target
-- date (an upcoming event); needed = a question item is answered Yes.
ALTER TABLE task_templates ADD COLUMN notify_on TEXT CHECK (notify_on IN ('complete', 'done', 'target', 'needed'));
ALTER TABLE task_templates ADD COLUMN notify_text TEXT;
-- Set once the rule's existing matches on every project were recorded as already handled,
-- so a new rule never floods projects with things that happened before it existed.
ALTER TABLE task_templates ADD COLUMN notify_baselined_at TEXT;

CREATE TABLE customer_notices (
	id          INTEGER PRIMARY KEY,
	project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
	task_id     INTEGER REFERENCES project_tasks(id) ON DELETE SET NULL,
	template_id INTEGER REFERENCES task_templates(id) ON DELETE SET NULL,
	notify_on   TEXT    NOT NULL,
	event_date  TEXT,            -- YYYY-MM-DD: completed date, or the target date for 'target'
	body        TEXT    NOT NULL,
	owner_id    INTEGER REFERENCES users(id),
	created_by  INTEGER REFERENCES users(id),
	created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	cleared_at  TEXT,
	cleared_by  INTEGER REFERENCES users(id),
	outcome     TEXT    CHECK (outcome IN ('notified', 'not_notified', 'baseline')),
	clear_note  TEXT
);
CREATE INDEX idx_customer_notices_project ON customer_notices(project_id, cleared_at);
CREATE INDEX idx_customer_notices_task ON customer_notices(task_id);

-- Starting rules, matched by name; anything renamed on a live template is set from Lists > Task template.
UPDATE task_templates SET notify_on = 'complete', notify_text = 'Plans complete: permit and interconnection applications go in next'
	WHERE parent_id IS NULL AND name = 'Design';
UPDATE task_templates SET notify_on = 'complete', notify_text = 'Zoning permit received'
	WHERE parent_id IS NULL AND name = 'Zoning permit';
UPDATE task_templates SET notify_on = 'complete', notify_text = 'Building permit received'
	WHERE parent_id IS NULL AND name = 'Building permit';
UPDATE task_templates SET notify_on = 'done', notify_text = 'Interconnection applied: watch for the utility''s email to sign the agreement'
	WHERE name = 'Applied (app and fee)' AND parent_id IN (SELECT id FROM task_templates WHERE parent_id IS NULL AND name = 'Interconnection');
UPDATE task_templates SET notify_on = 'complete', notify_text = 'Interconnection approved: permission to install'
	WHERE parent_id IS NULL AND name = 'Interconnection';
UPDATE task_templates SET notify_on = 'done', notify_text = 'Utility upgrade required: send the cost and the utility''s invoice'
	WHERE name = 'Invoice received' AND parent_id IN (SELECT id FROM task_templates WHERE parent_id IS NULL AND name = 'Utility upgrade');
UPDATE task_templates SET notify_on = 'target', notify_text = 'Installation scheduled'
	WHERE name = 'Started' AND parent_id IN (SELECT id FROM task_templates WHERE parent_id IS NULL AND name = 'Installation');
UPDATE task_templates SET notify_on = 'complete', notify_text = 'Passed final inspection: final invoice coming'
	WHERE parent_id IS NULL AND name = 'Final inspection';
UPDATE task_templates SET notify_on = 'done', notify_text = 'COC submitted: watch for the utility''s e-sign email'
	WHERE name = 'COC submitted' AND parent_id IN (SELECT id FROM task_templates WHERE parent_id IS NULL AND name = 'Utility');
UPDATE task_templates SET notify_on = 'done', notify_text = 'PTO received: entering closeout, what to expect (SRECs, warranty registrations)'
	WHERE name = 'PTO received' AND parent_id IN (SELECT id FROM task_templates WHERE parent_id IS NULL AND name = 'Utility');
UPDATE task_templates SET notify_on = 'complete', notify_text = 'SolarInsure warranty registered'
	WHERE parent_id IS NULL AND name = 'SolarInsure registration';
UPDATE task_templates SET notify_on = 'complete', notify_text = 'SolarEdge extended warranty registered'
	WHERE parent_id IS NULL AND name = 'SolarEdge extended warranty';
UPDATE task_templates SET notify_on = 'needed', notify_text = 'Virtual net metering: send the form for signature'
	WHERE parent_id IS NULL AND name = 'Virtual net metering';
