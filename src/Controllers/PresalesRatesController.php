<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Db;
use App\Presales;
use App\View;

/**
 * Pre-Sales > Rates: tax, labor, contingency rules and cap, commission table, deposit tiers and
 * payment schedules. New build sheets copy these in; saved build sheets keep their own copies.
 */
final class PresalesRatesController
{
	public function show(): void
	{
		$old = $_SESSION['old_rates'] ?? null;
		unset($_SESSION['old_rates']);
		$settings = [];
		foreach (array_keys(Presales::SETTINGS) as $k) {
			$settings[$k] = $old['settings'][$k] ?? Presales::setting($k);
		}
		View::render('presales/rates', [
			'title' => 'Buildsheet rates',
			'settings' => $settings,
			'commission' => $old['commission'] ?? array_map(static fn ($c) => [
				'rate' => Presales::field((float) $c['rate'] * 100, 4), 'adjusted' => Presales::field((float) $c['adjusted'] * 100, 4),
				'step_down' => $c['step_down'],
			], Presales::commissionRates()),
			'commissionDefault' => $old['commission_default'] ?? (string) array_search(1, array_map('intval', array_column(Presales::commissionRates(), 'is_default')), true),
			'templateDefault' => $old['template_default'] ?? (string) array_search(1, array_map('intval', array_column(Presales::paymentTemplates(), 'is_default')), true),
			'deposits' => $old['deposits'] ?? Presales::depositTiers(),
			'templates' => $old['templates'] ?? array_map(static function ($t) {
				$t['lines'] = implode("\n", array_map(static fn ($m) => $m['label'] . ' = ' . rtrim(rtrim(number_format((float) $m['pct'], 2, '.', ''), '0'), '.'), json_decode($t['milestones'], true) ?: []));
				return $t;
			}, Presales::paymentTemplates()),
			'rules' => $old['rules'] ?? Presales::contingencyRules(false),
			'canEdit' => Presales::canEdit(),
			'activity' => Activity::feed('presales_rates', 1),
		]);
	}

	public function save(): void
	{
		Presales::requireEdit();
		try {
			$changes = Db::transaction(fn () => $this->apply());
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_rates'] = $_POST;
			flash('error', $e->getMessage() . ' Nothing was saved.');
			redirect('/presales/rates');
		}
		Presales::forgetSettings();
		if ($changes) {
			Activity::event('presales_rates', 1, 'Rates saved: ' . implode('; ', $changes));
		}
		flash('success', $changes ? 'Rates saved. New build sheets use them; saved build sheets keep their own.' : 'No changes.');
		redirect('/presales/rates');
	}

	private function apply(): array
	{
		$changes = [];
		$num = static function ($v, string $label, bool $blankNull = false): ?float {
			$v = trim(str_replace([',', '$', '%'], '', (string) $v));
			if ($v === '') {
				return $blankNull ? null : 0.0;
			}
			if (!is_numeric($v) || (float) $v < 0) {
				throw new \InvalidArgumentException($label . ' must be a number (0 or more).');
			}
			return (float) $v;
		};

		// Single values. Percent fields are typed as percents (6 = 6%).
		$percent = ['tax_rate' => true, 'markup_default' => true];
		$numeric = ['tax_rate', 'markup_default', 'labor_default', 'labor_rate', 'lodging_rate', 'per_diem_rate', 'site_visit', 'contingency_cap', 'contingency_floor', 'steep_deg', 'stale_days', 'round_to'];
		foreach (Presales::SETTINGS as $k => [$default, $label]) {
			$raw = (string) ($_POST['settings'][$k] ?? '');
			if ($k === 'labor_default' && ($num($raw, $label) ?? 0) <= 0) {
				throw new \InvalidArgumentException('The default labor price can\'t be zero.');
			}
			if (in_array($k, $numeric, true)) {
				$v = $num($raw, $label);
				$value = isset($percent[$k]) ? (string) round($v / 100, 6) : (string) $v;
			} else {
				$value = trim($raw);
			}
			$have = Presales::setting($k);
			if ((string) $have !== $value && !(is_numeric($have) && is_numeric($value) && abs((float) $have - (float) $value) < 0.0000001)) {
				Db::run('INSERT OR REPLACE INTO presales_settings (key, value, updated_at) VALUES (?, ?, ?)', [$k, $value, now_utc()]);
				$changes[] = $label;
			}
		}

		// Commission table
		$rows = [];
		$default = (string) ($_POST['commission_default'] ?? '');
		foreach ((array) ($_POST['commission'] ?? []) as $i => $r) {
			$rate = $num($r['rate'] ?? '', 'Commission rate', true);
			if ($rate === null) {
				continue;
			}
			$adj = $num($r['adjusted'] ?? '', 'Adjusted commission', true) ?? $rate;
			$rows[] = ['rate' => round($rate / 100, 6), 'adjusted' => round($adj / 100, 6), 'step_down' => $num($r['step_down'] ?? '', 'Step-down'),
				'is_default' => (string) $i === $default ? 1 : 0];
		}
		if (count(array_unique(array_column($rows, 'rate'))) !== count($rows)) {
			throw new \InvalidArgumentException('Each commission rate can only be listed once.');
		}
		if ($rows && !array_filter(array_column($rows, 'is_default'))) {
			$rows[0]['is_default'] = 1;
		}
		$changes = array_merge($changes, $this->replace('presales_commission_rates', $rows, ['rate', 'adjusted', 'step_down', 'is_default'], 'commission table'));

		// Deposit tiers
		$rows = [];
		foreach ((array) ($_POST['deposits'] ?? []) as $r) {
			$amount = $num($r['amount'] ?? '', 'Deposit', true);
			if ($amount === null) {
				continue;
			}
			$class = ($r['class'] ?? '') === 'commercial' ? 'commercial' : 'residential';
			$min = $num($r['min_kw'] ?? '', 'Deposit kW from');
			$max = $num($r['max_kw'] ?? '', 'Deposit kW to', true);
			if ($max !== null && $max <= $min) {
				throw new \InvalidArgumentException('A deposit tier\'s "up to" kW must be more than its "from" kW.');
			}
			$rows[] = ['class' => $class, 'min_kw' => $min, 'max_kw' => $max, 'amount' => $amount, 'sort_order' => count($rows) + 1];
		}
		$changes = array_merge($changes, $this->replace('presales_deposit_tiers', $rows, ['class', 'min_kw', 'max_kw', 'amount', 'sort_order'], 'deposit tiers'));

		// Payment schedules: one milestone per line, "Label = percent"
		$rows = [];
		$tdefault = (string) ($_POST['template_default'] ?? '');
		foreach ((array) ($_POST['templates'] ?? []) as $i => $t) {
			$name = trim((string) ($t['name'] ?? ''));
			$text = trim((string) ($t['lines'] ?? ''));
			if ($name === '' && $text === '') {
				continue;
			}
			if ($name === '') {
				throw new \InvalidArgumentException('Each payment schedule needs a name.');
			}
			$ms = [];
			foreach (preg_split('/\R/', $text) as $line) {
				if (trim($line) === '') {
					continue;
				}
				if (!preg_match('/^(.+?)\s*=\s*([\d.]+)\s*%?$/', trim($line), $m)) {
					throw new \InvalidArgumentException('Payment schedule "' . $name . '": write each payment as "Label = percent", e.g. "Final payment = 25".');
				}
				$ms[] = ['label' => trim($m[1]), 'pct' => (float) $m[2]];
			}
			$total = array_sum(array_column($ms, 'pct'));
			if (!$ms || abs($total - 100) > 0.001) {
				throw new \InvalidArgumentException('Payment schedule "' . $name . '" adds up to ' . $total . '% of the balance after the deposit; it must be 100%.');
			}
			$rows[] = ['name' => $name, 'milestones' => json_encode($ms), 'is_default' => (string) $i === $tdefault ? 1 : 0, 'sort_order' => count($rows) + 1];
		}
		if (count(array_unique(array_column($rows, 'name'))) !== count($rows)) {
			throw new \InvalidArgumentException('Payment schedule names must be different.');
		}
		if ($rows && !array_filter(array_column($rows, 'is_default'))) {
			$rows[0]['is_default'] = 1;
		}
		$changes = array_merge($changes, $this->replace('presales_payment_templates', $rows, ['name', 'milestones', 'is_default', 'sort_order'], 'payment schedules'));

		// Contingency rules (rule ids are kept, because build sheets remember checked manual rules by id)
		$keep = [];
		$ruleChanged = false;
		foreach ((array) ($_POST['rules'] ?? []) as $r) {
			$name = trim((string) ($r['name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$trigger = array_key_exists((string) ($r['trigger'] ?? ''), Presales::TRIGGERS) ? $r['trigger'] : 'manual';
			$row = ['name' => $name, 'pct' => $num($r['pct'] ?? '', 'Contingency %'), 'reason' => trim((string) ($r['reason'] ?? '')) ?: null,
				'trigger' => $trigger, 'is_active' => empty($r['is_active']) ? 0 : 1, 'sort_order' => count($keep) + 1];
			$rid = (int) ($r['id'] ?? 0);
			$have = $rid ? Db::one('SELECT * FROM presales_contingency_rules WHERE id = ?', [$rid]) : null;
			if ($have) {
				foreach ($row as $k => $v) {
					if ((string) $have[$k] !== (string) $v && !(is_numeric($have[$k]) && is_numeric($v) && abs((float) $have[$k] - (float) $v) < 0.0000001)) {
						$ruleChanged = true;
					}
				}
				Db::update('presales_contingency_rules', $rid, $row);
				$keep[] = $rid;
			} else {
				if (Db::value('SELECT 1 FROM presales_contingency_rules WHERE name = ?', [$name])) {
					throw new \InvalidArgumentException('There is already a contingency rule named "' . $name . '".');
				}
				$keep[] = Db::insert('presales_contingency_rules', $row);
				$ruleChanged = true;
			}
		}
		$ph = $keep ? implode(',', array_fill(0, count($keep), '?')) : '0';
		if (Db::value("SELECT COUNT(*) FROM presales_contingency_rules WHERE id NOT IN ($ph)", $keep)) {
			Db::run("DELETE FROM presales_contingency_rules WHERE id NOT IN ($ph)", $keep);
			$ruleChanged = true;
		}
		if ($ruleChanged) {
			$changes[] = 'contingency rules';
		}
		return $changes;
	}

	/** Replace a small table's rows when they differ; returns the change note. */
	private function replace(string $table, array $rows, array $cols, string $label): array
	{
		$have = Db::all('SELECT ' . implode(', ', $cols) . " FROM $table ORDER BY id");
		$norm = static fn (array $rs) => json_encode(array_map(static fn ($r) => array_map(static fn ($v) => is_numeric($v) ? round((float) $v, 6) : $v, array_values(array_intersect_key($r, array_flip($cols)))), $rs));
		if ($norm($have) === $norm($rows)) {
			return [];
		}
		Db::run("DELETE FROM $table");
		foreach ($rows as $r) {
			Db::insert($table, array_intersect_key($r, array_flip($cols)));
		}
		return [$label];
	}
}
