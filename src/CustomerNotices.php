<?php
declare(strict_types=1);

namespace App;

/**
 * Customer updates: template items with a notify rule queue a line on the project when they
 * happen ("Building permit received"). Lines collect until someone marks them Customer notified
 * or Not notified. Uncleared lines stay in step with the tasks: if what raised one is undone,
 * the line is withdrawn; cleared lines are history and never change.
 *
 * Rules (task_templates.notify_on):
 *  - complete: a top-level task is complete (every sub-task completed or not needed, at least one
 *    completed; a task without sub-tasks has a completed date)
 *  - done:     this item has a completed date
 *  - target:   this item has a target date and no completed date (an upcoming event); a changed
 *              target date adds a new line once the old one was cleared
 * Under any rule, a changed date updates the uncleared line.
 *  - needed:   a question item is answered Yes
 */
final class CustomerNotices
{
	public const RULES = [
		'complete' => 'When the task is complete',
		'done'     => 'When completed',
		'target'   => 'When a target date is set or changed',
		'needed'   => 'When answered Yes (needed)',
	];

	/** Which rules fit a top-level task and which fit a sub-task. */
	public const FOR_TASK = ['complete', 'done', 'target', 'needed'];
	public const FOR_SUB = ['done', 'target'];

	public const OUTCOMES = ['notified' => 'Customer notified', 'not_notified' => 'Not notified'];

	private static ?array $rules = null;

	/** @return array<int, array{notify_on:string, notify_text:?string}> template id => rule */
	public static function rules(): array
	{
		if (self::$rules === null) {
			self::$rules = [];
			foreach (Db::all('SELECT id, notify_on, notify_text FROM task_templates WHERE notify_on IS NOT NULL') as $r) {
				self::$rules[(int) $r['id']] = $r;
			}
		}
		return self::$rules;
	}

	/** Forget the cached rules after the template changes. */
	public static function reset(): void
	{
		self::$rules = null;
	}

	/** Short description of a rule, for the marker on project tasks. */
	public static function ruleTitle(array $rule): string
	{
		$when = match ($rule['notify_on']) {
			'complete' => 'when this task is complete',
			'done' => 'when this is completed',
			'target' => 'when a target date is set',
			'needed' => 'when this is answered Yes',
			default => '',
		};
		return 'Customer update ' . $when . ($rule['notify_text'] ? ': ' . $rule['notify_text'] : '');
	}

	/**
	 * What should be on the project right now: one entry per rule that is currently met.
	 * @param array[] $rows the project's project_tasks rows
	 * @param int[]|null $onlyTemplates limit to these template ids
	 * @return array<string, array> key => task_id, template_id, notify_on, event_date, body, owner_id
	 */
	public static function due(array $rows, ?array $onlyTemplates = null): array
	{
		$rules = self::rules();
		if (!$rules) {
			return [];
		}
		$byId = [];
		$subs = [];
		foreach ($rows as $r) {
			$byId[(int) $r['id']] = $r;
			if ($r['parent_id'] !== null) {
				$subs[(int) $r['parent_id']][] = $r;
			}
		}
		$needed = static fn (array $r) => (string) $r['needed'] !== '0';
		$out = [];
		foreach ($rows as $r) {
			$tpl = $r['template_id'] !== null ? (int) $r['template_id'] : 0;
			if (!$tpl || !isset($rules[$tpl]) || ($onlyTemplates !== null && !in_array($tpl, $onlyTemplates, true))) {
				continue;
			}
			$rule = $rules[$tpl];
			$parent = $r['parent_id'] !== null ? ($byId[(int) $r['parent_id']] ?? null) : null;
			if (!$needed($r) || ($parent && !$needed($parent))) {
				continue;
			}
			$on = $rule['notify_on'];
			$date = null;
			if ($on === 'complete') {
				if ($parent) {
					continue;
				}
				$kids = $subs[(int) $r['id']] ?? [];
				if (!$kids) {
					$date = $r['done_date'] ?: null;
				} else {
					$ok = true;
					foreach ($kids as $k) {
						if (!Tasks::leafResolved($k)) {
							$ok = false;
							break;
						}
						if ($needed($k) && $k['done_date'] && ($date === null || $k['done_date'] > $date)) {
							$date = $k['done_date'];
						}
					}
					if (!$ok) {
						$date = null;
					}
				}
				if ($date === null) {
					continue;
				}
			} elseif ($on === 'done') {
				if (!$r['done_date']) {
					continue;
				}
				$date = $r['done_date'];
			} elseif ($on === 'target') {
				if (!$r['target_date'] || $r['done_date']) {
					continue;
				}
				$date = $r['target_date'];
			} elseif ($on === 'needed') {
				if ((string) $r['needed'] !== '1') {
					continue;
				}
			} else {
				continue;
			}
			$label = $parent ? $parent['name'] . ' > ' . $r['name'] : $r['name'];
			$out[$on . ':' . $r['id']] = [
				'task_id' => (int) $r['id'], 'template_id' => $tpl, 'notify_on' => $on, 'event_date' => $date,
				'body' => trim((string) $rule['notify_text']) !== '' ? trim((string) $rule['notify_text']) : $label,
				'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : ($parent && $parent['owner_id'] !== null ? (int) $parent['owner_id'] : null),
			];
		}
		return $out;
	}

	/** Bring every non-cancelled project's uncleared lines up to date (for list pages). */
	public static function syncAll(): void
	{
		self::baselinePending();
		if (!self::rules()) {
			return;
		}
		$ids = array_map('intval', array_column(Db::all("SELECT id FROM projects WHERE COALESCE(hold_state, '') <> 'cancelled'"), 'id'));
		$tasks = Tasks::forProjects($ids);
		$notices = self::byProject($ids);
		self::inTransaction(static function () use ($ids, $tasks, $notices) {
			foreach ($ids as $id) {
				self::apply($id, self::due($tasks[$id] ?? []), $notices[$id] ?? []);
			}
		});
	}

	/** Bring one project's uncleared lines up to date. Returns its uncleared lines. */
	public static function sync(int $projectId): array
	{
		self::baselinePending();
		$cancelled = (string) Db::value('SELECT hold_state FROM projects WHERE id = ?', [$projectId]) === 'cancelled';
		if (self::rules() && !$cancelled) {
			$rows = Tasks::forProjects([$projectId])[$projectId] ?? [];
			$existing = self::byProject([$projectId])[$projectId] ?? [];
			self::inTransaction(static fn () => self::apply($projectId, self::due($rows), $existing));
		}
		return self::pending($projectId);
	}

	/** Add what is newly due and withdraw uncleared lines that no longer are. */
	private static function apply(int $projectId, array $due, array $existing): void
	{
		$open = [];      // key => uncleared row
		$seen = [];      // key => true when any line (cleared or not) exists for it
		$seenDates = []; // target key => dates already raised
		foreach ($existing as $n) {
			$key = $n['notify_on'] . ':' . (int) $n['task_id'];
			if ($n['task_id'] === null) {
				$key = 'orphan:' . $n['id'];
			}
			$seen[$key] = true;
			$seenDates[$key][(string) $n['event_date']] = true;
			if ($n['cleared_at'] === null) {
				$open[$key] = $n;
			}
		}
		foreach ($due as $key => $d) {
			if (isset($open[$key])) {
				// A changed date (rescheduled event, corrected completed date) updates the line nobody
				// has acted on yet
				if ((string) $open[$key]['event_date'] !== (string) $d['event_date']) {
					Db::update('customer_notices', (int) $open[$key]['id'], ['event_date' => $d['event_date']]);
					Activity::event('project', $projectId, 'Customer update changed: ' . $d['body'] . ', now ' . fmt_date($d['event_date']));
				}
				unset($open[$key]);
				continue;
			}
			if (isset($seen[$key]) && ($d['notify_on'] !== 'target' || isset($seenDates[$key][(string) $d['event_date']]))) {
				continue; // already raised, and cleared
			}
			if ($d['notify_on'] === 'target' && isset($seen[$key])) {
				$d['body'] .= ' (date changed)';
			}
			Db::insert('customer_notices', $d + ['project_id' => $projectId, 'created_by' => Auth::id()]);
			Activity::event('project', $projectId, 'Customer update added: ' . self::line($d));
		}
		foreach ($open as $n) {
			Db::run('DELETE FROM customer_notices WHERE id = ?', [(int) $n['id']]);
			Activity::event('project', $projectId, 'Customer update withdrawn: ' . self::line($n));
		}
	}

	/** The text of one line with its date, as shown on the project. */
	public static function line(array $n): string
	{
		if (!$n['event_date']) {
			return $n['body'];
		}
		return $n['body'] . ($n['notify_on'] === 'target' ? ' for ' : ', ') . fmt_date($n['event_date']);
	}

	/**
	 * Record a rule's current matches on every project as already handled, without logging.
	 * Runs for rules whose notify_baselined_at is blank: new rules, or a rule just changed.
	 */
	public static function baselinePending(): void
	{
		$tpls = array_map('intval', array_column(Db::all('SELECT id FROM task_templates WHERE notify_on IS NOT NULL AND notify_baselined_at IS NULL'), 'id'));
		if (!$tpls) {
			return;
		}
		$ids = array_map('intval', array_column(Db::all('SELECT id FROM projects'), 'id'));
		$tasks = Tasks::forProjects($ids);
		$notices = self::byProject($ids);
		self::inTransaction(static function () use ($tpls, $ids, $tasks, $notices) {
			$now = now_utc();
			foreach ($ids as $id) {
				$have = [];
				foreach ($notices[$id] ?? [] as $n) {
					$have[$n['notify_on'] . ':' . $n['task_id'] . ':' . $n['event_date']] = true;
				}
				foreach (self::due($tasks[$id] ?? [], $tpls) as $d) {
					if (isset($have[$d['notify_on'] . ':' . $d['task_id'] . ':' . $d['event_date']])) {
						continue;
					}
					Db::insert('customer_notices', $d + ['project_id' => $id, 'cleared_at' => $now, 'outcome' => 'baseline']);
				}
			}
			Db::run('UPDATE task_templates SET notify_baselined_at = ? WHERE id IN (' . implode(',', $tpls) . ')', [$now]);
		});
	}

	/** Uncleared lines on a project, oldest first, with the owner. */
	public static function pending(int $projectId): array
	{
		return Db::all(
			'SELECT n.*, u.name AS owner_name, u.initials AS owner_initials
			 FROM customer_notices n LEFT JOIN users u ON u.id = n.owner_id
			 WHERE n.project_id = ? AND n.cleared_at IS NULL ORDER BY n.created_at, n.id',
			[$projectId]
		);
	}

	/** Cleared lines a person acted on (baseline rows left out), newest first. */
	public static function history(int $projectId, int $limit = 50): array
	{
		return Db::all(
			"SELECT n.*, u.name AS cleared_by_name
			 FROM customer_notices n LEFT JOIN users u ON u.id = n.cleared_by
			 WHERE n.project_id = ? AND n.cleared_at IS NOT NULL AND n.outcome <> 'baseline'
			 ORDER BY n.cleared_at DESC, n.id LIMIT " . max(1, $limit),
			[$projectId]
		);
	}

	/** Every uncleared line on non-cancelled projects, for the dashboard and Projects list. */
	public static function allPending(): array
	{
		return Db::all(
			"SELECT n.*, u.name AS owner_name, u.initials AS owner_initials, p.project_number, p.name AS project_name
			 FROM customer_notices n
			 JOIN projects p ON p.id = n.project_id
			 LEFT JOIN users u ON u.id = n.owner_id
			 WHERE n.cleared_at IS NULL AND COALESCE(p.hold_state, '') <> 'cancelled'
			 ORDER BY p.project_number, n.created_at, n.id"
		);
	}

	/** project id => number of uncleared lines */
	public static function pendingCounts(): array
	{
		$out = [];
		foreach (Db::all('SELECT project_id, COUNT(*) AS n FROM customer_notices WHERE cleared_at IS NULL GROUP BY project_id') as $r) {
			$out[(int) $r['project_id']] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Clear the given uncleared lines (the ones the person saw) as notified or not notified.
	 * @param int[] $ids
	 * @return int lines cleared
	 */
	public static function clear(int $projectId, array $ids, string $outcome, ?string $note): int
	{
		if (!isset(self::OUTCOMES[$outcome]) || !$ids) {
			return 0;
		}
		$in = implode(',', array_map('intval', $ids));
		$rows = Db::all("SELECT * FROM customer_notices WHERE project_id = ? AND cleared_at IS NULL AND id IN ($in) ORDER BY created_at, id", [$projectId]);
		if (!$rows) {
			return 0;
		}
		self::inTransaction(static function () use ($projectId, $rows, $outcome, $note) {
			$now = now_utc();
			foreach ($rows as $n) {
				Db::update('customer_notices', (int) $n['id'], ['cleared_at' => $now, 'cleared_by' => Auth::id(), 'outcome' => $outcome, 'clear_note' => $note]);
			}
			Activity::event('project', $projectId, self::OUTCOMES[$outcome] . ': ' . implode('; ', array_map(self::line(...), $rows)) . ($note !== null ? ' (' . $note . ')' : ''));
			Db::update('projects', $projectId, ['updated_at' => $now]);
		});
		return count($rows);
	}

	/** Uncleared lines shaped for the project page script. */
	public static function forClient(array $pending): array
	{
		return array_map(static fn (array $n) => [
			'id' => (int) $n['id'], 'text' => self::line($n), 'owner' => (string) ($n['owner_initials'] ?: $n['owner_name'] ?? ''),
			'mine' => $n['owner_id'] !== null && (int) $n['owner_id'] === Auth::id(),
		], $pending);
	}

	/** @return array<int, array[]> project id => every notice row */
	private static function byProject(array $ids): array
	{
		if (!$ids) {
			return [];
		}
		$out = [];
		foreach (Db::all('SELECT * FROM customer_notices WHERE project_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id') as $n) {
			$out[(int) $n['project_id']][] = $n;
		}
		return $out;
	}

	private static function inTransaction(callable $fn): void
	{
		if (Db::pdo()->inTransaction()) {
			$fn();
			return;
		}
		Db::transaction($fn);
	}
}
