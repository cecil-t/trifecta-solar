-- Tasks: one-off to-dos assigned to a person, separate from the project task lists.
-- (Called "todos" internally because "tasks" already means project task-list items.)
CREATE TABLE todos (
    id          INTEGER PRIMARY KEY,
    title       TEXT NOT NULL,
    details     TEXT,
    assigned_to INTEGER NOT NULL REFERENCES users(id),
    due_on      TEXT,
    project_id  INTEGER REFERENCES projects(id) ON DELETE SET NULL,
    service_id  INTEGER REFERENCES service_tickets(id) ON DELETE SET NULL,
    done_at     TEXT,
    done_by     INTEGER REFERENCES users(id),
    created_by  INTEGER REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_todos_assignee ON todos(assigned_to, done_at);
CREATE INDEX idx_todos_project ON todos(project_id);
CREATE INDEX idx_todos_service ON todos(service_id);
