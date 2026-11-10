<?php
declare(strict_types=1);

namespace App;

/**
 * Overdue flags on active projects (open, not on hold, not cancelled):
 * - Missed target: a project task or sub-task with a target date before today, no completed date,
 *   not marked Not needed, and not under a parent marked Not needed (the same rule as the cream
 *   and red Target cells on the project page).
 * - Overdue task: an open to-do (Tasks menu) linked to the project with a due date before today.
 * Computed on each view; nothing is stored.
 */
final class Overdue
{
	/** Days past target when a missed target turns red, matching the Target cell. */
	public const LATE_DAYS = 30;

	/** Whether a project gets flags: open (not completed or cancelled) and not on hold. */
	public static function applies(array $project, string $phase): bool
	{
		return !in_array($phase, ['complete', 'cancelled'], true)
			&& !in_array((string) ($project['hold_state'] ?? ''), ['on_hold', 'cancelled'], true);
	}

	/**
	 * Missed targets from one project's task rows (flat, as Tasks::forProjects gives them).
	 *
	 * @return list<array{id:int,label:string,target:string,days:int,owner_id:?int}>
	 */
	public static function missed(array $rows, ?string $today = null): array
	{
		$today ??= date('Y-m-d');
		$byId = [];
		foreach ($rows as $r) {
			$byId[(int) $r['id']] = $r;
		}
		$out = [];
		foreach ($rows as $r) {
			$target = (string) ($r['target_date'] ?? '');
			if ($target === '' || $target >= $today || !empty($r['done_date']) || (string) $r['needed'] === '0') {
				continue;
			}
			$parent = $r['parent_id'] ? ($byId[(int) $r['parent_id']] ?? null) : null;
			if ($parent && (string) $parent['needed'] === '0') {
				continue;
			}
			$out[] = [
				'id' => (int) $r['id'],
				'label' => $parent ? $parent['name'] . ' › ' . $r['name'] : (string) $r['name'],
				'target' => $target,
				'days' => self::daysLate($target, $today),
				'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : null,
			];
		}
		usort($out, static fn ($a, $b) => [$a['target'], $a['id']] <=> [$b['target'], $b['id']]);
		return $out;
	}

	/**
	 * Open to-dos past their due date, by project id.
	 *
	 * @param int[] $projectIds
	 * @return array<int, list<array{id:int,title:string,due:string,days:int,owner_id:int}>>
	 */
	public static function todos(array $projectIds, ?string $today = null): array
	{
		if (!$projectIds) {
			return [];
		}
		$today ??= date('Y-m-d');
		$in = implode(',', array_map('intval', $projectIds));
		$out = [];
		foreach (Db::all("SELECT id, project_id, title, due_on, assigned_to FROM todos
				WHERE project_id IN ($in) AND done_at IS NULL AND due_on IS NOT NULL AND due_on <> '' AND due_on < ?
				ORDER BY due_on, id", [$today]) as $d) {
			$out[(int) $d['project_id']][] = [
				'id' => (int) $d['id'],
				'title' => (string) $d['title'],
				'due' => (string) $d['due_on'],
				'days' => self::daysLate((string) $d['due_on'], $today),
				'owner_id' => (int) $d['assigned_to'],
			];
		}
		return $out;
	}

	/**
	 * Both flags for one project, or empty lists when the project doesn't get flags.
	 *
	 * @return array{missed:list<array>,todos:list<array>}
	 */
	public static function forProject(array $project, string $phase, array $rows): array
	{
		if (!self::applies($project, $phase)) {
			return ['missed' => [], 'todos' => []];
		}
		return ['missed' => self::missed($rows), 'todos' => self::todos([(int) $project['id']])[(int) $project['id']] ?? []];
	}

	/** True when any missed target is LATE_DAYS or more past, which turns the chip red. */
	public static function late(array $missed): bool
	{
		foreach ($missed as $m) {
			if ($m['days'] >= self::LATE_DAYS) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lines for the project page card and its live update: text, date line, owner initials, link.
	 *
	 * @return array{missed:list<array>,todos:list<array>,late:bool}
	 */
	public static function forClient(array $flags): array
	{
		$users = [];
		foreach (Db::all('SELECT id, name, initials FROM users') as $u) {
			$users[(int) $u['id']] = ['initials' => (string) ($u['initials'] ?: mb_substr((string) $u['name'], 0, 1)), 'name' => (string) $u['name']];
		}
		$me = (int) Auth::id();
		// Overdue to-dos always read red, like the Tasks list; missed targets turn red at LATE_DAYS.
		$line = static function (array $x, string $text, string $dateLabel, string $date, string $href, bool $red) use ($users, $me): array {
			$owner = $x['owner_id'] !== null ? ($users[$x['owner_id']] ?? null) : null;
			return [
				'text' => $text,
				'when' => $dateLabel . ' ' . fmt_date($date) . ', ' . $x['days'] . ($x['days'] === 1 ? ' day' : ' days') . ' late',
				'late' => $red || $x['days'] >= self::LATE_DAYS,
				'owner' => $owner['initials'] ?? '',
				'owner_name' => $owner['name'] ?? '',
				'mine' => $x['owner_id'] !== null && $x['owner_id'] === $me,
				'href' => $href,
			];
		};
		return [
			'missed' => array_map(static fn ($m) => $line($m, $m['label'], 'Target', $m['target'], '#task-' . $m['id'], false), $flags['missed']),
			'todos' => array_map(static fn ($d) => $line($d, $d['title'], 'Due', $d['due'], '/tasks/' . $d['id'], true), $flags['todos']),
			'late' => self::late($flags['missed']),
		];
	}

	private static function daysLate(string $date, string $today): int
	{
		return (int) round((strtotime($today . ' 12:00') - strtotime($date . ' 12:00')) / 86400);
	}
}
