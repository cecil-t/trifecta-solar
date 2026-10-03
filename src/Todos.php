<?php
declare(strict_types=1);

namespace App;

/** Tasks (one-off assigned to-dos). Stored as "todos" to keep clear of project task lists. */
final class Todos
{
    private const SELECT = "SELECT d.*, a.name AS assignee_name, a.initials AS assignee_initials,
            c.name AS creator_name, c.initials AS creator_initials, db.name AS done_by_name,
            p.project_number, p.name AS project_name, s.ticket_number, so.name AS service_customer
        FROM todos d
        JOIN users a ON a.id = d.assigned_to
        LEFT JOIN users c ON c.id = d.created_by
        LEFT JOIN users db ON db.id = d.done_by
        LEFT JOIN projects p ON p.id = d.project_id
        LEFT JOIN service_tickets s ON s.id = d.service_id
        LEFT JOIN organizations so ON so.id = s.customer_id";

    /** Open first by due date (no date last), then newest. */
    private const ORDER = ' ORDER BY d.done_at IS NOT NULL, d.due_on IS NULL, d.due_on, d.created_at DESC';

    public static function find(int $id): ?array
    {
        return Db::one(self::SELECT . ' WHERE d.id = ?', [$id]);
    }

    public static function where(string $sql, array $params = [], int $limit = 500): array
    {
        return Db::all(self::SELECT . ' WHERE ' . $sql . self::ORDER . ' LIMIT ' . $limit, $params);
    }

    public static function openFor(int $userId): array
    {
        return self::where('d.done_at IS NULL AND d.assigned_to = ?', [$userId]);
    }

    public static function forProject(int $projectId): array
    {
        return self::where('d.project_id = ? AND d.done_at IS NULL', [$projectId]);
    }

    public static function forService(int $serviceId): array
    {
        return self::where('d.service_id = ? AND d.done_at IS NULL', [$serviceId]);
    }

    /** "overdue", "today" or "" for styling a due date. */
    public static function dueState(?string $due, ?string $doneAt = null): string
    {
        if (!$due || $doneAt) {
            return '';
        }
        $today = date('Y-m-d');
        return $due < $today ? 'overdue' : ($due === $today ? 'today' : '');
    }

    /** Short label for what a task is linked to. */
    public static function linkLabel(array $d): ?array
    {
        if ($d['project_id']) {
            return ['/projects/' . (int) $d['project_id'], $d['project_number'] . ' ' . $d['project_name']];
        }
        if ($d['service_id']) {
            return ['/service/' . (int) $d['service_id'], $d['ticket_number'] . ' ' . $d['service_customer']];
        }
        return null;
    }
}
