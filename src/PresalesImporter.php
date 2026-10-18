<?php
declare(strict_types=1);

namespace App;

/**
 * Loads presales.json (from tools/import/build_presales.py): catalog items, buildsheet rates,
 * commission table, deposit tiers, payment schedules and contingency rules.
 * Add-only: existing catalog items (same category and name) are not changed, except that blank
 * filter fields (segment, mounts, domestic content, FEOC) are filled in; a setting or table row
 * that already exists is never changed. Edit those on the Catalog and Rates pages.
 */
final class PresalesImporter
{
	private array $log = [];
	private array $counts = ['catalog_added' => 0, 'catalog_skipped' => 0, 'catalog_filled' => 0, 'settings_added' => 0, 'settings_kept' => 0,
		'commission_added' => 0, 'deposits_added' => 0, 'schedules_added' => 0, 'rules_added' => 0];

	public function __construct(private bool $dryRun)
	{
	}

	public function run(array $data): array
	{
		if (($data['kind'] ?? '') !== 'presales') {
			throw new \InvalidArgumentException('This is not a presales import file (expected "kind": "presales").');
		}
		$work = function () use ($data): void {
			$this->settings((array) ($data['settings'] ?? []));
			$this->commission((array) ($data['commission_rates'] ?? []));
			$this->deposits((array) ($data['deposit_tiers'] ?? []));
			$this->schedules((array) ($data['payment_templates'] ?? []));
			$this->rules((array) ($data['contingency_rules'] ?? []));
			$this->catalog((array) ($data['catalog'] ?? []));
			if ($this->dryRun) {
				throw new DryRunDone();
			}
		};
		try {
			Db::transaction($work);
		} catch (DryRunDone) {
			// rolled back on purpose
		}
		return ['log' => $this->log, 'counts' => $this->counts];
	}

	private function settings(array $settings): void
	{
		foreach ($settings as $key => $value) {
			if (!array_key_exists($key, Presales::SETTINGS)) {
				$this->log[] = "  setting $key: not a known setting, skipped";
				continue;
			}
			$have = Db::value('SELECT value FROM presales_settings WHERE key = ?', [$key]);
			if ($have !== null && $have !== '') {
				$this->counts['settings_kept']++;
				continue;
			}
			Db::run('INSERT OR REPLACE INTO presales_settings (key, value, updated_at) VALUES (?, ?, ?)', [$key, (string) $value, now_utc()]);
			$this->counts['settings_added']++;
			$this->log[] = "  setting $key = " . mb_strimwidth((string) $value, 0, 60, '...');
		}
	}

	private function commission(array $rows): void
	{
		$hasDefault = (bool) Db::value('SELECT 1 FROM presales_commission_rates WHERE is_default = 1');
		foreach ($rows as $r) {
			$rate = (float) $r['rate'];
			if (Db::value('SELECT 1 FROM presales_commission_rates WHERE ABS(rate - ?) < 0.000001', [$rate])) {
				continue;
			}
			$default = !$hasDefault && !empty($r['is_default']) ? 1 : 0;
			$hasDefault = $hasDefault || $default;
			Db::insert('presales_commission_rates', ['rate' => $rate, 'adjusted' => (float) $r['adjusted'], 'step_down' => (float) ($r['step_down'] ?? 0), 'is_default' => $default]);
			$this->counts['commission_added']++;
		}
	}

	private function deposits(array $rows): void
	{
		if (Db::value('SELECT COUNT(*) FROM presales_deposit_tiers')) {
			$this->log[] = '  deposit tiers already set, kept as they are';
			return;
		}
		foreach (array_values($rows) as $i => $r) {
			if (!in_array($r['class'] ?? '', ['residential', 'commercial'], true)) {
				continue;
			}
			Db::insert('presales_deposit_tiers', ['class' => $r['class'], 'min_kw' => (float) ($r['min_kw'] ?? 0),
				'max_kw' => isset($r['max_kw']) ? (float) $r['max_kw'] : null, 'amount' => (float) $r['amount'], 'sort_order' => $i + 1]);
			$this->counts['deposits_added']++;
		}
	}

	private function schedules(array $rows): void
	{
		$hasDefault = (bool) Db::value('SELECT 1 FROM presales_payment_templates WHERE is_default = 1');
		foreach (array_values($rows) as $i => $r) {
			$name = trim((string) ($r['name'] ?? ''));
			if ($name === '' || Db::value('SELECT 1 FROM presales_payment_templates WHERE name = ?', [$name])) {
				continue;
			}
			$total = array_sum(array_map(static fn ($m) => (float) $m['pct'], (array) $r['milestones']));
			if (abs($total - 100) > 0.001) {
				$this->log[] = "  schedule \"$name\": milestones total $total%, not 100%, skipped";
				continue;
			}
			$default = !$hasDefault && !empty($r['is_default']) ? 1 : 0;
			$hasDefault = $hasDefault || $default;
			Db::insert('presales_payment_templates', ['name' => $name, 'milestones' => json_encode(array_values($r['milestones'])), 'is_default' => $default, 'sort_order' => $i + 1]);
			$this->counts['schedules_added']++;
		}
	}

	private function rules(array $rows): void
	{
		foreach (array_values($rows) as $i => $r) {
			$name = trim((string) ($r['name'] ?? ''));
			if ($name === '' || Db::value('SELECT 1 FROM presales_contingency_rules WHERE name = ?', [$name])) {
				continue;
			}
			$trigger = array_key_exists($r['trigger'] ?? '', Presales::TRIGGERS) ? $r['trigger'] : 'manual';
			Db::insert('presales_contingency_rules', ['name' => $name, 'pct' => (float) $r['pct'], 'reason' => $r['reason'] ?? null,
				'trigger' => $trigger, 'sort_order' => $i + 1]);
			$this->counts['rules_added']++;
		}
	}

	private function catalog(array $items): void
	{
		$cols = ['category', 'name', 'notes', 'print_notes', 'price', 'shipping', 'setup_cost', 'min_total', 'qty_rule', 'tax_class',
			'watts', 'ac_kw', 'kwh', 'kw', 'warranty_text', 'ext_warranty_years', 'ext_warranty_cost', 'siw_cost', 'is_solarinsure',
			'price_updated_at', 'price_source', 'sort_order', 'segment', 'mounts', 'domestic_content_pct', 'feoc_ok', 'needs_mlpe', 'trench_part', 'part_size', 'material',
			'cec_name', 'cec_units', 'spec', 'degradation_first_pct', 'degradation_annual_pct', 'product_warranty_years', 'performance_warranty_years',
			'length_mm', 'width_mm'];
		$fillable = ['segment', 'mounts', 'domestic_content_pct', 'feoc_ok', 'needs_mlpe',
			'cec_name', 'cec_units', 'degradation_first_pct', 'degradation_annual_pct', 'product_warranty_years', 'performance_warranty_years',
			'length_mm', 'width_mm'];
		foreach ($items as $it) {
			if (!isset(Presales::CATEGORIES[$it['category'] ?? '']) || trim((string) ($it['name'] ?? '')) === '') {
				$this->log[] = '  catalog: skipped an item with an unknown category or no name';
				continue;
			}
			$have = Db::one('SELECT * FROM catalog_items WHERE category = ? AND name = ? COLLATE NOCASE', [$it['category'], trim($it['name'])]);
			if ($have) {
				$fill = [];
				foreach ($fillable as $c) {
					$blank = $have[$c] === null || $have[$c] === '' || ($c === 'needs_mlpe' && !(int) $have[$c]);
					if ($blank && isset($it[$c]) && $it[$c] !== '' && !($c === 'needs_mlpe' && !(int) $it[$c])) {
						$fill[$c] = $it[$c];
					}
				}
				if (isset($fill['segment']) && !in_array($fill['segment'], ['residential', 'commercial'], true)) {
					unset($fill['segment']);
				}
				foreach (['length_mm', 'width_mm'] as $c) {
					if (isset($fill[$c]) && ($have['category'] !== 'modules' || !is_numeric($fill[$c]) || (float) $fill[$c] < 300 || (float) $fill[$c] > 4000)) {
						unset($fill[$c]);
					}
				}
				if (isset($fill['cec_name']) && !$this->cecOk((string) $it['category'], (string) $fill['cec_name'], (string) $have['name'])) {
					unset($fill['cec_name'], $fill['cec_units']);
				}
				if ($fill) {
					Db::update('catalog_items', (int) $have['id'], $fill);
					$this->counts['catalog_filled']++;
					$this->log[] = '  filled ' . implode(', ', array_keys($fill)) . ': ' . $have['name'];
					if (!$this->dryRun) {
						Activity::event('catalog_item', (int) $have['id'], 'Filled in by import: ' . implode(', ', array_keys($fill)), null);
					}
				} else {
					$this->counts['catalog_skipped']++;
				}
				continue;
			}
			$row = [];
			foreach ($cols as $c) {
				$row[$c] = $it[$c] ?? null;
			}
			$row['name'] = trim((string) $row['name']);
			$row['print_notes'] = (int) ($row['print_notes'] ?? 0);
			$row['is_solarinsure'] = (int) ($row['is_solarinsure'] ?? 0);
			$row['shipping'] = (float) ($row['shipping'] ?? 0);
			$row['setup_cost'] = (float) ($row['setup_cost'] ?? 0);
			$row['qty_rule'] = array_key_exists((string) $row['qty_rule'], Presales::QTY_RULES) ? $row['qty_rule'] : 'manual';
			$row['tax_class'] = array_key_exists((string) $row['tax_class'], Presales::TAX_CLASSES) ? $row['tax_class'] : 'bos';
			$row['needs_mlpe'] = (int) ($row['needs_mlpe'] ?? 0);
			$row['segment'] = in_array($row['segment'], ['residential', 'commercial'], true) ? $row['segment'] : null;
			if ($row['cec_name'] !== null && !$this->cecOk((string) $row['category'], (string) $row['cec_name'], (string) $row['name'])) {
				$row['cec_name'] = $row['cec_units'] = null;
			}
			$id = Db::insert('catalog_items', $row);
			if (!$this->dryRun) {
				Activity::event('catalog_item', $id, 'Added by the buildsheet import', null);
			}
			$this->counts['catalog_added']++;
		}
		$this->log[] = '  catalog: ' . $this->counts['catalog_added'] . ' added, ' . $this->counts['catalog_filled'] . ' filled in, ' . $this->counts['catalog_skipped'] . ' unchanged';
	}

	/** A CEC model name is kept only when SAM's library lists it for that kind of item. */
	private function cecOk(string $category, string $cec, string $item): bool
	{
		$kind = CecLibrary::kindFor($category);
		if ($kind === null || (CecLibrary::available() && !CecLibrary::find($kind, $cec))) {
			$this->log[] = '  skipped SAM model "' . $cec . '" for ' . $item . ': not in SAM\'s ' . ($kind ?? 'CEC') . ' library';
			return false;
		}
		return true;
	}
}

/** Thrown inside the transaction to roll a dry run back. */
final class DryRunDone extends \RuntimeException
{
}
