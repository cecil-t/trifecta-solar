-- Utility rebate gets a third step, "Rebate check cut": the date the utility's portal shows the
-- rebate check was cut, so the rebate counts as completed only once it is approved and paid.
-- Added to the template (matched by name, in case ids differ) and, add-only, under every
-- project's existing Utility rebate task that doesn't have it yet. Nothing else changes.

INSERT INTO task_templates (parent_id, name, phase, sort_order, default_needed, applies_when, default_owner_id)
SELECT t.id, 'Rebate check cut', t.phase,
	(SELECT COALESCE(MAX(s.sort_order), 0) + 10 FROM task_templates s WHERE s.parent_id = t.id), 1, 'all', t.default_owner_id
FROM task_templates t
WHERE t.parent_id IS NULL AND t.name = 'Utility rebate'
	AND NOT EXISTS (SELECT 1 FROM task_templates s WHERE s.parent_id = t.id AND s.name = 'Rebate check cut');

-- Log it first (while the step is still missing), as adding a sub-task by hand does; only where
-- the rebate is Yes or not answered, so projects answered No don't get log noise.
INSERT INTO activity_log (entity_type, entity_id, kind, body)
SELECT 'project', r.project_id, 'event', 'Added sub-task "Rebate check cut" under Utility rebate (task template update)'
FROM project_tasks r
WHERE r.parent_id IS NULL AND r.name = 'Utility rebate' AND (r.needed IS NULL OR r.needed <> 0)
	AND NOT EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = r.id AND c.name = 'Rebate check cut');

INSERT INTO project_tasks (project_id, parent_id, template_id, name, phase, sort_order, needed, owner_id)
SELECT r.project_id, r.id,
	(SELECT s.id FROM task_templates s JOIN task_templates t ON t.id = s.parent_id
	 WHERE t.parent_id IS NULL AND t.name = 'Utility rebate' AND s.name = 'Rebate check cut' ORDER BY s.id LIMIT 1),
	'Rebate check cut', r.phase,
	(SELECT COALESCE(MAX(c.sort_order), 0) + 10 FROM project_tasks c WHERE c.parent_id = r.id), 1, r.owner_id
FROM project_tasks r
WHERE r.parent_id IS NULL AND r.name = 'Utility rebate'
	AND NOT EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = r.id AND c.name = 'Rebate check cut');
