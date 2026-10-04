<?php
declare(strict_types=1);

namespace App;

/**
 * Adds Tasks (to-dos) from a JSON file: {"assign_to": "email", "source": "...", "todos": [{"title": "...", "details": null}]}.
 * Add-only: a task whose title matches an open task for the same person is skipped, so a
 * second run adds nothing. No due date, project or service; those are set by hand afterwards.
 * Everything runs in one transaction; --dry-run rolls it back after reporting.
 */
final class TodoImporter
{
	public function __construct(private bool $dryRun = false)
	{
	}

	/** @return array{counts:array, log:string[]} */
	public function run(array $data): array
	{
		$user = Db::one('SELECT id, name FROM users WHERE email = ?', [$data['assign_to'] ?? ''])
			?? throw new \InvalidArgumentException('assign_to must be the email of an existing user.');
		$source = $data['source'] ?? 'import';
		$counts = ['added' => 0, 'skipped' => 0];
		$log = [];
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			foreach ($data['todos'] ?? [] as $t) {
				$title = trim((string) ($t['title'] ?? ''));
				if ($title === '') {
					continue;
				}
				if (Db::value('SELECT id FROM todos WHERE assigned_to = ? AND done_at IS NULL AND title = ? COLLATE NOCASE', [$user['id'], $title])) {
					$counts['skipped']++;
					$log[] = "skip   $title (already an open task)";
					continue;
				}
				$id = Db::insert('todos', ['title' => $title, 'details' => $t['details'] ?? null, 'assigned_to' => $user['id']]);
				Activity::event('todo', $id, 'Assigned to ' . $user['name'] . ' (imported from ' . $source . ')', null);
				$counts['added']++;
				$log[] = "add    $title";
			}
			$this->dryRun ? $pdo->rollBack() : $pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return ['counts' => $counts, 'log' => $log];
	}
}
