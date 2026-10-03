<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Projects;
use App\Service;
use App\Todos;
use App\View;

/** Tasks: one-off to-dos anyone can assign to anyone. Shown to users as "Tasks". */
final class TodoController
{
    private const TRACKED = [
        'title' => 'Task', 'details' => 'Details', 'assigned_to' => 'Assigned to', 'due_on' => 'Due',
        'project_id' => 'Project', 'service_id' => 'Service ticket',
    ];
    public const TABS = ['mine' => 'My tasks', 'assigned' => 'Assigned by me', 'open' => 'All open', 'done' => 'Completed'];

    public function index(): void
    {
        $tab = isset(self::TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'mine';
        $who = (int) ($_GET['who'] ?? 0);
        $q = trim((string) ($_GET['q'] ?? ''));
        $me = (int) Auth::id();
        [$sql, $params] = match ($tab) {
            'assigned' => ['d.done_at IS NULL AND d.created_by = ? AND d.assigned_to <> ?', [$me, $me]],
            'open' => ['d.done_at IS NULL', []],
            'done' => ['d.done_at IS NOT NULL', []],
            default => ['d.done_at IS NULL AND d.assigned_to = ?', [$me]],
        };
        if ($who && in_array($tab, ['open', 'done'], true)) {
            $sql .= ' AND d.assigned_to = ?';
            $params[] = $who;
        }
        if ($q !== '') {
            $sql .= ' AND (d.title LIKE ? OR d.details LIKE ? OR p.name LIKE ? OR p.project_number LIKE ? OR s.ticket_number LIKE ?)';
            array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
        }
        $rows = Todos::where($sql, $params);
        if ($tab === 'done') {
            usort($rows, static fn ($a, $b) => strcmp((string) $b['done_at'], (string) $a['done_at']));
        }
        $counts = [
            'mine' => (int) Db::value('SELECT COUNT(*) FROM todos WHERE done_at IS NULL AND assigned_to = ?', [$me]),
            'assigned' => (int) Db::value('SELECT COUNT(*) FROM todos WHERE done_at IS NULL AND created_by = ? AND assigned_to <> ?', [$me, $me]),
            'open' => (int) Db::value('SELECT COUNT(*) FROM todos WHERE done_at IS NULL'),
            'done' => (int) Db::value('SELECT COUNT(*) FROM todos WHERE done_at IS NOT NULL'),
        ];
        View::render('tasks/index', [
            'title' => 'Tasks', 'rows' => $rows, 'tab' => $tab, 'who' => $who, 'q' => $q, 'counts' => $counts,
            'users' => Projects::users(),
        ]);
    }

    public function create(): void
    {
        $d = ['assigned_to' => (int) ($_GET['to'] ?? 0) ?: null, 'project_id' => (int) ($_GET['project'] ?? 0) ?: null,
            'service_id' => (int) ($_GET['service'] ?? 0) ?: null, 'back' => $this->back('/tasks')];
        $this->form($d, null);
    }

    public function edit(int $id): void
    {
        $this->form($this->find($id) + ['back' => $this->back('/tasks/' . $id)], $id);
    }

    private function form(array $d, ?int $id): void
    {
        if ($old = $_SESSION['old_todo'] ?? null) {
            $d = array_merge($d, $old);
            unset($_SESSION['old_todo']);
        }
        View::render('tasks/form', [
            'title' => $id ? 'Edit task' : 'Assign a task', 'd' => $d, 'id' => $id,
            'users' => Projects::users(),
            'projects' => Db::all("SELECT id, project_number, name FROM projects WHERE COALESCE(hold_state, '') <> 'cancelled' ORDER BY project_number DESC"),
            'tickets' => Db::all('SELECT t.id, t.ticket_number, c.name AS customer FROM service_tickets t LEFT JOIN organizations c ON c.id = t.customer_id ORDER BY t.ticket_number DESC LIMIT 300'),
        ]);
    }

    public function store(): void
    {
        try {
            $data = $this->input();
        } catch (\InvalidArgumentException $e) {
            $_SESSION['old_todo'] = $_POST;
            flash('error', $e->getMessage());
            redirect('/tasks/new');
        }
        $id = Db::insert('todos', $data + ['created_by' => Auth::id()]);
        $assignee = (string) Db::value('SELECT name FROM users WHERE id = ?', [$data['assigned_to']]);
        Activity::event('todo', $id, 'Assigned to ' . $assignee . ($data['due_on'] ? ', due ' . fmt_date($data['due_on']) : ''));
        $this->logOnParent($data, 'Task assigned to ' . $assignee . ': ' . $data['title']);
        flash('success', (int) $data['assigned_to'] === Auth::id() ? 'Task added to your list.' : 'Task assigned to ' . $assignee . '.');
        redirect($this->back('/tasks/' . $id));
    }

    public function update(int $id): void
    {
        $before = $this->find($id);
        try {
            $data = $this->input();
        } catch (\InvalidArgumentException $e) {
            $_SESSION['old_todo'] = $_POST;
            flash('error', $e->getMessage());
            redirect('/tasks/' . $id . '/edit');
        }
        Db::update('todos', $id, $data + ['updated_at' => now_utc()]);
        Activity::changes('todo', $id, $before, $data, self::TRACKED, [
            'assigned_to' => static fn ($v) => (string) Db::value('SELECT name FROM users WHERE id = ?', [$v]),
            'due_on' => static fn ($v) => fmt_date($v),
            'project_id' => static fn ($v) => (string) Db::value("SELECT project_number || ' ' || name FROM projects WHERE id = ?", [$v]),
            'service_id' => static fn ($v) => (string) Db::value('SELECT ticket_number FROM service_tickets WHERE id = ?', [$v]),
        ]);
        flash('success', 'Saved.');
        redirect($this->back('/tasks/' . $id));
    }

    /** Check off or reopen. */
    public function toggle(int $id): void
    {
        $d = $this->find($id);
        if ($d['done_at']) {
            Db::update('todos', $id, ['done_at' => null, 'done_by' => null, 'updated_at' => now_utc()]);
            Activity::event('todo', $id, 'Reopened');
        } else {
            Db::update('todos', $id, ['done_at' => now_utc(), 'done_by' => Auth::id(), 'updated_at' => now_utc()]);
            Activity::event('todo', $id, 'Marked done');
            $this->logOnParent($d, 'Task done: ' . $d['title']);
        }
        redirect($this->back('/tasks/' . $id));
    }

    public function show(int $id): void
    {
        $d = $this->find($id);
        View::render('tasks/show', ['title' => $d['title'], 'd' => $d, 'activity' => Activity::feed('todo', $id)]);
    }

    public function comment(int $id): void
    {
        $this->find($id);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '') {
            Activity::comment('todo', $id, $body);
            Db::update('todos', $id, ['updated_at' => now_utc()]);
        }
        redirect('/tasks/' . $id . '#log');
    }

    /** The person who assigned it, the assignee, or an admin can delete a task. */
    public function delete(int $id): void
    {
        $d = $this->find($id);
        if (!Auth::isAdmin() && (int) $d['created_by'] !== Auth::id() && (int) $d['assigned_to'] !== Auth::id()) {
            flash('error', 'Only the person who assigned it, the assignee, or an admin can delete a task.');
            redirect('/tasks/' . $id);
        }
        Db::run("DELETE FROM activity_log WHERE entity_type = 'todo' AND entity_id = ?", [$id]);
        Db::run('DELETE FROM todos WHERE id = ?', [$id]);
        $this->logOnParent($d, 'Task deleted: ' . $d['title']);
        flash('success', 'Task deleted.');
        redirect('/tasks');
    }

    private function input(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $to = (int) ($_POST['assigned_to'] ?? 0);
        $due = Projects::parseDate($_POST['due_on'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('Say what needs to be done.');
        }
        if (!$to || !Db::value('SELECT 1 FROM users WHERE id = ? AND is_active = 1', [$to])) {
            throw new \InvalidArgumentException('Choose who the task is for.');
        }
        if (trim((string) ($_POST['due_on'] ?? '')) !== '' && !$due) {
            throw new \InvalidArgumentException('Use a due date like ' . date('m/d/Y') . '.');
        }
        $project = (int) ($_POST['project_id'] ?? 0) ?: null;
        $service = (int) ($_POST['service_id'] ?? 0) ?: null;
        return [
            'title' => mb_substr($title, 0, 300), 'details' => trim((string) ($_POST['details'] ?? '')) ?: null,
            'assigned_to' => $to, 'due_on' => $due,
            'project_id' => $project && Db::value('SELECT 1 FROM projects WHERE id = ?', [$project]) ? $project : null,
            'service_id' => $service && Db::value('SELECT 1 FROM service_tickets WHERE id = ?', [$service]) ? $service : null,
        ];
    }

    /** Note the task on the linked project or service ticket's log too. */
    private function logOnParent(array $d, string $body): void
    {
        if (!empty($d['project_id'])) {
            Activity::event('project', (int) $d['project_id'], $body);
        }
        if (!empty($d['service_id'])) {
            Activity::event('service', (int) $d['service_id'], $body);
        }
    }

    /** Local return path from ?back= / POST back, else the default. */
    private function back(string $default): string
    {
        $b = (string) ($_POST['back'] ?? $_GET['back'] ?? '');
        return str_starts_with($b, '/') && !str_starts_with($b, '//') ? $b : $default;
    }

    private function find(int $id): array
    {
        $d = Todos::find($id);
        if (!$d) {
            View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
            exit;
        }
        return $d;
    }
}
