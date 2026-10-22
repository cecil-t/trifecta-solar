<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\CustomerNotices;
use App\Db;
use App\Projects;
use App\Tasks;
use App\View;

/** Admin editor for the default task template. Changes apply to new projects only. */
final class TemplateController
{
	private const FIELDS = ['name', 'phase', 'sort_order', 'default_needed', 'applies_when', 'gate', 'ref_label', 'default_owner_id', 'is_active', 'notify_on', 'notify_text'];

	public function index(): void
	{
		$rows = Db::all('SELECT t.*, u.initials AS owner_initials FROM task_templates t LEFT JOIN users u ON u.id = t.default_owner_id ORDER BY t.sort_order, t.id');
		$byParent = [];
		foreach ($rows as $r) {
			$byParent[$r['parent_id'] ?? 0][] = $r;
		}
		$tree = array_fill_keys(array_keys(Tasks::PHASES), []);
		foreach ($byParent[0] ?? [] as $t) {
			$t['subs'] = $byParent[$t['id']] ?? [];
			$tree[$t['phase']][] = $t;
		}
		View::render('template/index', ['title' => 'Task template', 'tree' => $tree]);
	}

	public function edit(int $id): void
	{
		$t = Db::one('SELECT * FROM task_templates WHERE id = ?', [$id]) ?? redirect('/admin/template');
		$parent = $t['parent_id'] ? Db::one('SELECT * FROM task_templates WHERE id = ?', [$t['parent_id']]) : null;
		View::render('template/form', ['title' => 'Edit template item', 't' => $t, 'parent' => $parent, 'users' => Projects::users()]);
	}

	public function create(): void
	{
		$parentId = (int) ($_GET['parent'] ?? 0) ?: null;
		$parent = $parentId ? Db::one('SELECT * FROM task_templates WHERE id = ? AND parent_id IS NULL', [$parentId]) : null;
		$phase = $parent['phase'] ?? (isset(Tasks::PHASES[$_GET['phase'] ?? '']) ? $_GET['phase'] : 'pre_install');
		$sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM task_templates WHERE ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL AND phase = ?'), [$parent ? $parent['id'] : $phase]);
		$t = ['id' => null, 'parent_id' => $parent['id'] ?? null, 'name' => '', 'phase' => $phase, 'sort_order' => $sort,
			'default_needed' => 1, 'applies_when' => 'all', 'gate' => null, 'ref_label' => null,
			'default_owner_id' => $parent ? null : \App\Auth::id(), 'is_active' => 1, 'notify_on' => null, 'notify_text' => null];
		View::render('template/form', ['title' => 'Add template item', 't' => $t, 'parent' => $parent, 'users' => Projects::users()]);
	}

	public function store(): void
	{
		$data = $this->input();
		$data['parent_id'] = (int) ($_POST['parent_id'] ?? 0) ?: null;
		$data = $this->fitRule($data, $data['parent_id'] !== null);
		if ($data['parent_id']) {
			$data['phase'] = Db::value('SELECT phase FROM task_templates WHERE id = ?', [$data['parent_id']]) ?? $data['phase'];
		}
		if ($data['name'] === '') {
			flash('error', 'Name is required.');
			redirect('/admin/template');
		}
		$id = Db::insert('task_templates', $data);
		Activity::event('task_template', $id, 'Added template item "' . $data['name'] . '"');
		CustomerNotices::reset();
		CustomerNotices::baselinePending();
		flash('success', 'Added. New projects will include it; existing projects can add it from the template picker.');
		redirect('/admin/template#tpl-' . $id);
	}

	public function update(int $id): void
	{
		$before = Db::one('SELECT * FROM task_templates WHERE id = ?', [$id]) ?? redirect('/admin/template');
		$data = $this->input();
		if ($data['name'] === '') {
			flash('error', 'Name is required.');
			redirect('/admin/template/' . $id);
		}
		if ($before['parent_id']) {
			$data['phase'] = $before['phase'];
		}
		$data = $this->fitRule($data, $before['parent_id'] !== null);
		if ($data['notify_on'] !== $before['notify_on']) {
			// A new or changed rule starts from what has already happened on existing projects
			$data['notify_baselined_at'] = null;
		}
		Db::transaction(function () use ($id, $before, $data) {
			Db::update('task_templates', $id, $data);
			if (!$before['parent_id'] && $data['phase'] !== $before['phase']) {
				Db::run('UPDATE task_templates SET phase = ? WHERE parent_id = ?', [$data['phase'], $id]);
			}
			Activity::changes('task_template', $id, $before, $data, array_combine(self::FIELDS, self::FIELDS));
		});
		CustomerNotices::reset();
		CustomerNotices::baselinePending();
		$ruleChanged = $data['notify_on'] !== $before['notify_on'] || $data['notify_text'] !== $before['notify_text'];
		flash('success', $ruleChanged
			? 'Template updated. The customer update rule applies to existing projects from now on; nothing already done raises a line.'
			: 'Template updated. Existing projects are unchanged.');
		redirect('/admin/template#tpl-' . $id);
	}

	private function input(): array
	{
		$s = static fn (string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $v;
		return [
			'name' => (string) $s('name'),
			'phase' => isset(Tasks::PHASES[$_POST['phase'] ?? '']) ? $_POST['phase'] : 'pre_install',
			'sort_order' => (int) ($_POST['sort_order'] ?? 0),
			'default_needed' => match ($_POST['default_needed'] ?? '') { '1' => 1, '0' => 0, default => null },
			'applies_when' => in_array($_POST['applies_when'] ?? '', ['all', 'pv', 'storage'], true) ? $_POST['applies_when'] : 'all',
			'gate' => isset(Tasks::GATES[$_POST['gate'] ?? '']) ? $_POST['gate'] : null,
			'ref_label' => $s('ref_label'),
			'default_owner_id' => ((int) ($_POST['default_owner_id'] ?? 0)) ?: null,
			'is_active' => empty($_POST['is_active']) ? 0 : 1,
			'notify_on' => isset(CustomerNotices::RULES[$_POST['notify_on'] ?? '']) ? $_POST['notify_on'] : null,
			'notify_text' => ($t = $s('notify_text')) === null ? null : mb_substr($t, 0, 200),
		];
	}

	/** Sub-tasks can only notify when completed or scheduled; no rule means no text. */
	private function fitRule(array $data, bool $isSub): array
	{
		if ($data['notify_on'] !== null && !in_array($data['notify_on'], $isSub ? CustomerNotices::FOR_SUB : CustomerNotices::FOR_TASK, true)) {
			$data['notify_on'] = null;
		}
		if ($data['notify_on'] === null) {
			$data['notify_text'] = null;
		}
		return $data;
	}
}
