-- Clear to install waits for Interconnection > Approved / permission to install. Conditional
-- approval no longer counts on its own. Gates are copied onto each project's tasks, so the
-- template item and every project copy change together.
UPDATE task_templates SET gate = NULL WHERE id = 14 AND gate = 'install_prereq';
UPDATE project_tasks SET gate = NULL WHERE template_id = 14 AND gate = 'install_prereq';
