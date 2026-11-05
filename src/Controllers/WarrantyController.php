<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Projects;
use App\Warranties;

/** The Warranties card on a project: program, typed start date, and the stored warranty lines. */
final class WarrantyController
{
	/** Program and typed start date. */
	public function saveProgram(int $id): void
	{
		$p = $this->project($id);
		$program = (string) ($_POST['warranty_program'] ?? '');
		$program = isset(Warranties::PROGRAMS[$program]) ? $program : null;
		$raw = trim((string) ($_POST['warranty_start'] ?? ''));
		$start = Projects::parseDate($raw);
		if ($raw !== '' && !$start) {
			flash('error', 'Use a date like ' . date('m/d/Y') . ' for the warranty start.');
			redirect('/projects/' . $id . '#warranties');
		}
		$after = ['warranty_program' => $program, 'warranty_start' => $start];
		$n = Activity::changes('project', $id, $p, $after,
			['warranty_program' => 'Warranty program', 'warranty_start' => 'Warranty start'],
			['warranty_program' => static fn ($v) => $v === null || $v === '' ? 'From the SolarInsure question' : (Warranties::PROGRAMS[$v] ?? $v),
				'warranty_start' => static fn ($v) => $v ? fmt_date($v) : 'From the steps']);
		if ($n) {
			Db::update('projects', $id, $after + ['updated_at' => now_utc()]);
			flash('success', 'Warranty saved.');
		}
		redirect('/projects/' . $id . '#warranties');
	}

	public function add(int $id): void
	{
		$this->project($id);
		try {
			$w = $this->input();
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage());
			redirect('/projects/' . $id . '#warranties');
		}
		Db::insert('project_warranties', $w + ['project_id' => $id, 'source' => 'manual', 'created_by' => Auth::id()]);
		Activity::event('project', $id, 'Added warranty: ' . $this->summary($w));
		redirect('/projects/' . $id . '#warranties');
	}

	public function update(int $id, int $wid): void
	{
		$this->project($id);
		$before = $this->line($id, $wid);
		try {
			$w = $this->input();
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage());
			redirect('/projects/' . $id . '#warranties');
		}
		if ($this->summary($before) !== $this->summary($w) || (string) $before['note'] !== (string) $w['note']) {
			Db::update('project_warranties', $wid, $w + ['updated_at' => now_utc()]);
			Activity::event('project', $id, 'Changed warranty from "' . $this->summary($before) . '" to "' . $this->summary($w) . '"');
		}
		redirect('/projects/' . $id . '#warranties');
	}

	public function delete(int $id, int $wid): void
	{
		$this->project($id);
		$before = $this->line($id, $wid);
		Db::run('DELETE FROM project_warranties WHERE id = ?', [$wid]);
		Activity::event('project', $id, 'Removed warranty: ' . $this->summary($before));
		redirect('/projects/' . $id . '#warranties');
	}

	private function input(): array
	{
		$kind = (string) ($_POST['kind'] ?? '');
		if (!isset(Warranties::KINDS[$kind])) {
			throw new \InvalidArgumentException('Pick what kind of warranty this is.');
		}
		$years = Projects::parseNumber($_POST['term_years'] ?? '');
		if ($years === null || $years <= 0 || $years > 50) {
			throw new \InvalidArgumentException('Warranty years should be between 1 and 50.');
		}
		$basis = (string) ($_POST['start_basis'] ?? 'install');
		if (!isset(Warranties::BASES[$basis])) {
			$basis = 'install';
		}
		$raw = trim((string) ($_POST['start_date'] ?? ''));
		$start = Projects::parseDate($raw);
		if ($raw !== '' && !$start) {
			throw new \InvalidArgumentException('Use a date like ' . date('m/d/Y') . ' for the start date, or leave it blank.');
		}
		$cost = Projects::parseMoney($_POST['cost'] ?? '');
		if ($cost !== null && ($cost < 0 || $cost > 100000000)) {
			throw new \InvalidArgumentException('Cost should be between $0 and $1,000,000.');
		}
		$kwh = Projects::parseNumber($_POST['guarantee_kwh'] ?? '');
		$rate = Projects::parseNumber($_POST['guarantee_rate'] ?? '');
		if ($kind === 'production_guarantee') {
			if ($kwh !== null && $kwh < 0) {
				throw new \InvalidArgumentException('Guaranteed kWh should be 0 or more.');
			}
			\App\UnitGuard::check('annual_kwh', $kwh, 'Guaranteed kWh');
			if ($rate !== null && ($rate < 0 || $rate > 5)) {
				throw new \InvalidArgumentException('The payout is in dollars per kWh short, e.g. 0.15.');
			}
		} else {
			$kwh = $rate = null;
		}
		$text = static fn (string $k, int $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max) ?: null;
		return [
			'kind' => $kind, 'provider' => $text('provider', 120), 'covers' => $text('covers', 200), 'term_years' => $years,
			'start_basis' => $basis, 'start_date' => $start, 'cost_cents' => $cost, 'reference' => $text('reference', 120),
			'guarantee_kwh' => $kwh, 'guarantee_rate' => $rate, 'note' => $text('note', 500),
		];
	}

	private function summary(array $w): string
	{
		$y = (float) $w['term_years'];
		$parts = [(Warranties::KINDS[$w['kind']] ?? $w['kind']) . ($w['provider'] ? ' (' . $w['provider'] . ')' : ''),
			rtrim(rtrim(number_format($y, 1), '0'), '.') . ' years from ' . Warranties::lc(Warranties::BASES[$w['start_basis']] ?? $w['start_basis'])];
		if ($w['start_date']) {
			$parts[] = 'starting ' . fmt_date($w['start_date']);
		}
		if ($w['covers']) {
			$parts[] = $w['covers'];
		}
		if ($w['cost_cents'] !== null) {
			$parts[] = Projects::money((int) $w['cost_cents']);
		}
		if ($w['reference']) {
			$parts[] = '#' . $w['reference'];
		}
		if ($w['guarantee_kwh'] !== null) {
			$parts[] = number_format((float) $w['guarantee_kwh']) . ' kWh guaranteed';
		}
		if ($w['guarantee_rate'] !== null) {
			$parts[] = '$' . number_format((float) $w['guarantee_rate'], 2) . '/kWh short';
		}
		return implode(', ', $parts);
	}

	private function project(int $id): array
	{
		$p = Db::one('SELECT * FROM projects WHERE id = ?', [$id]);
		if (!$p) {
			redirect('/projects');
		}
		return $p;
	}

	private function line(int $id, int $wid): array
	{
		$w = Db::one('SELECT * FROM project_warranties WHERE id = ? AND project_id = ?', [$wid, $id]);
		if (!$w) {
			redirect('/projects/' . $id . '#warranties');
		}
		return $w;
	}
}
