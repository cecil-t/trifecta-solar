<?php
declare(strict_types=1);

namespace App;

/**
 * The unified per-record log: human comments plus system-written change and event entries.
 * Every save path should call Activity::changes() so history is captured automatically.
 */
final class Activity
{
	public const COMMENT_EDIT_DAYS = 7;

	public static function comment(string $type, int $id, string $body): int
	{
		return Db::insert('activity_log', [
			'entity_type' => $type, 'entity_id' => $id, 'kind' => 'comment',
			'user_id' => Auth::id(), 'body' => trim($body),
		]);
	}

	public static function event(string $type, int $id, string $body, ?int $userId = null): void
	{
		Db::insert('activity_log', [
			'entity_type' => $type, 'entity_id' => $id, 'kind' => 'event',
			'user_id' => $userId ?? Auth::id(), 'body' => $body,
		]);
	}

	/**
	 * Log one 'change' row per field that differs between $before and $after.
	 * $labels maps field => human label; only fields listed there are tracked.
	 * $formatters optionally maps field => fn(raw) => display string (e.g. a user id to a name).
	 */
	public static function changes(string $type, int $id, array $before, array $after, array $labels, array $formatters = []): int
	{
		$count = 0;
		foreach ($labels as $field => $label) {
			if (!array_key_exists($field, $after)) {
				continue;
			}
			$old = $before[$field] ?? null;
			$new = $after[$field];
			if ((string) ($old ?? '') === (string) ($new ?? '')) {
				continue;
			}
			$fmt = $formatters[$field] ?? static fn ($v) => $v;
			Db::insert('activity_log', [
				'entity_type' => $type, 'entity_id' => $id, 'kind' => 'change',
				'user_id' => Auth::id(), 'field' => $label,
				'old_value' => $old === null ? null : (string) $fmt($old),
				'new_value' => $new === null ? null : (string) $fmt($new),
			]);
			$count++;
		}
		return $count;
	}

	public static function feed(string $type, int $id, bool $commentsOnly = false, int $limit = 200): array
	{
		$sql = 'SELECT a.*, u.name AS user_name, u.initials AS user_initials
				FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
				WHERE a.entity_type = ? AND a.entity_id = ? AND a.deleted_at IS NULL';
		if ($commentsOnly) {
			$sql .= " AND a.kind = 'comment'";
		}
		$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit;
		return Db::all($sql, [$type, $id]);
	}

	/** The most recent comment on a record (comments are only ever typed by users), or null. */
	public static function latestComment(string $type, int $id): ?array
	{
		return Db::one(
			"SELECT a.*, u.name AS user_name, u.initials AS user_initials
			 FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
			 WHERE a.entity_type = ? AND a.entity_id = ? AND a.kind = 'comment' AND a.deleted_at IS NULL
			 ORDER BY a.created_at DESC, a.id DESC LIMIT 1",
			[$type, $id]
		);
	}

	/** Authors may edit or delete their own comments for 7 days; system entries are never editable. */
	public static function canEdit(array $entry): bool
	{
		return $entry['kind'] === 'comment'
			&& $entry['deleted_at'] === null
			&& (int) $entry['user_id'] === Auth::id()
			&& strtotime($entry['created_at']) > time() - self::COMMENT_EDIT_DAYS * 86400;
	}

	public static function editComment(int $entryId, string $body): bool
	{
		$entry = Db::one('SELECT * FROM activity_log WHERE id = ?', [$entryId]);
		if (!$entry || !self::canEdit($entry)) {
			return false;
		}
		Db::update('activity_log', $entryId, ['body' => trim($body), 'edited_at' => now_utc()]);
		return true;
	}

	public static function deleteComment(int $entryId): bool
	{
		$entry = Db::one('SELECT * FROM activity_log WHERE id = ?', [$entryId]);
		if (!$entry || !self::canEdit($entry)) {
			return false;
		}
		Db::update('activity_log', $entryId, ['deleted_at' => now_utc()]);
		return true;
	}
}
