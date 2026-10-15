<?php
declare(strict_types=1);

namespace App;

/**
 * Build sheets (presales_designs): create with today's rates, read a form into rows, save,
 * duplicate, compare with the catalog, and run the pricing for display.
 */
final class Designs
{
	/** Fields copied from a catalog item into a line (the line's snapshot). */
	public const SNAPSHOT = ['name', 'qty_rule', 'price', 'shipping', 'setup_cost', 'min_total', 'tax_class', 'watts', 'ac_kw',
		'kwh', 'kw', 'ext_warranty_years', 'ext_warranty_cost', 'siw_cost', 'warranty_text', 'domestic_content_pct', 'feoc_ok', 'needs_mlpe'];
	/** The fields that make a line's price differ from the catalog today. */
	private const PRICE_FIELDS = ['price', 'shipping', 'setup_cost', 'min_total', 'ext_warranty_cost', 'siw_cost', 'tax_class'];

	public static function find(int $id): ?array
	{
		return Db::one('SELECT * FROM presales_designs WHERE id = ?', [$id]);
	}

	public static function lines(int $designId): array
	{
		return Db::all('SELECT * FROM presales_design_lines WHERE design_id = ? ORDER BY sort_order, id', [$designId]);
	}

	public static function arrays(int $designId): array
	{
		return Db::all('SELECT * FROM presales_design_arrays WHERE design_id = ? ORDER BY sort_order, id', [$designId]);
	}

	/** Trench builder rows: ['trenches' => [...], 'circuits' => [...]] (circuit segments decoded). */
	public static function trench(int $designId): array
	{
		$circuits = Db::all('SELECT * FROM presales_design_circuits WHERE design_id = ? ORDER BY sort_order, id', [$designId]);
		foreach ($circuits as &$c) {
			$c['segments'] = json_decode((string) $c['segments'], true) ?: [];
		}
		unset($c);
		return ['trenches' => Db::all('SELECT * FROM presales_design_trenches WHERE design_id = ? ORDER BY sort_order, id', [$designId]), 'circuits' => $circuits];
	}

	/** "<Opportunity name> Solar Project", numbered when the opportunity already has one. */
	public static function defaultName(int $oppId): string
	{
		$base = Db::value('SELECT name FROM presales_opportunities WHERE id = ?', [$oppId]) . ' Solar Project';
		$name = $base;
		$i = 2;
		while (Db::value('SELECT 1 FROM presales_designs WHERE opportunity_id = ? AND name = ?', [$oppId, $name])) {
			$name = $base . ' ' . $i++;
		}
		return $name;
	}

	/** A new build sheet with today's rates copied in. */
	public static function create(int $oppId, string $name): int
	{
		$commission = Db::one('SELECT * FROM presales_commission_rates ORDER BY is_default DESC, rate DESC LIMIT 1');
		$template = Db::one('SELECT * FROM presales_payment_templates ORDER BY is_default DESC, sort_order LIMIT 1');
		$warranty = Db::one("SELECT * FROM catalog_items WHERE category = 'warranty' AND is_active = 1 ORDER BY is_solarinsure, sort_order LIMIT 1");
		$sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM presales_designs WHERE opportunity_id = ?', [$oppId]);
		$id = Db::insert('presales_designs', [
			'opportunity_id' => $oppId,
			'name' => $name,
			'tax_rate' => Presales::num('tax_rate'),
			'tax_note' => Presales::setting('tax_note'),
			'markup' => Presales::num('markup_default'),
			'commission_rate' => $commission ? (float) $commission['rate'] : 0,
			'commission_adjusted' => $commission ? (float) $commission['adjusted'] : 0,
			'commission_step_down' => $commission ? (float) $commission['step_down'] : 0,
			'warranty_item_id' => $warranty['id'] ?? null,
			'warranty_price' => $warranty ? (float) $warranty['price'] : null,
			'warranty_name' => $warranty['name'] ?? null,
			'warranty_is_solarinsure' => (int) ($warranty['is_solarinsure'] ?? 0),
			'labor_mode' => 'per_watt',
			'labor_value' => Presales::num('labor_default'),
			'labor_rate' => Presales::num('labor_rate'),
			'lodging_rate' => Presales::num('lodging_rate'),
			'per_diem_rate' => Presales::num('per_diem_rate'),
			'site_visit' => Presales::num('site_visit'),
			'contingency_cap' => Presales::num('contingency_cap'),
			'contingency_floor' => Presales::num('contingency_floor'),
			'steep_deg' => Presales::num('steep_deg'),
			'payment_milestones' => $template['milestones'] ?? null,
			'payment_template_name' => $template['name'] ?? null,
			'sort_order' => $sort,
			'created_by' => Auth::id(),
			'updated_by' => Auth::id(),
		]);
		Activity::event('presales', $oppId, 'Build sheet "' . $name . '" created');
		return $id;
	}

	/** Copy a build sheet (its lines and arrays too), keeping its prices as they are. */
	public static function duplicate(array $d, string $name): int
	{
		return Db::transaction(static function () use ($d, $name): int {
			$row = $d;
			unset($row['id']);
			$row['name'] = $name;
			$row['sort_order'] = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM presales_designs WHERE opportunity_id = ?', [$d['opportunity_id']]);
			$row['created_by'] = Auth::id();
			$row['updated_by'] = Auth::id();
			$row['created_at'] = now_utc();
			$row['updated_at'] = now_utc();
			$newId = Db::insert('presales_designs', $row);
			foreach (self::lines((int) $d['id']) as $l) {
				unset($l['id']);
				$l['design_id'] = $newId;
				Db::insert('presales_design_lines', $l);
			}
			foreach (self::arrays((int) $d['id']) as $a) {
				unset($a['id']);
				$a['design_id'] = $newId;
				Db::insert('presales_design_arrays', $a);
			}
			foreach (['presales_design_trenches', 'presales_design_circuits'] as $table) {
				foreach (Db::all("SELECT * FROM $table WHERE design_id = ? ORDER BY sort_order, id", [(int) $d['id']]) as $r) {
					unset($r['id']);
					$r['design_id'] = $newId;
					Db::insert($table, $r);
				}
			}
			Activity::event('presales', (int) $d['opportunity_id'], 'Build sheet "' . $d['name'] . '" copied as "' . $name . '"');
			return $newId;
		});
	}

	/** A line's snapshot taken from a catalog item now. */
	public static function snapshot(array $item): array
	{
		$row = [];
		foreach (self::SNAPSHOT as $f) {
			$row[$f] = $item[$f];
		}
		$row['category'] = $item['category'];
		$row['catalog_item_id'] = (int) $item['id'];
		$row['price_date'] = $item['price_updated_at'];
		return $row;
	}

	/**
	 * Read the build sheet form into [design fields, lines, arrays, trench]. Throws with a message the
	 * page can show when something can't be read. Nothing is saved here (the live calculation
	 * uses the same reading).
	 */
	public static function fromInput(array $d, array $post, bool $strict = true): array
	{
		$catalog = [];
		foreach (Db::all('SELECT * FROM catalog_items') as $c) {
			$catalog[(int) $c['id']] = $c;
		}
		$existing = [];
		foreach (self::lines((int) $d['id']) as $l) {
			$existing[(int) $l['id']] = $l;
		}
		$num = static function ($v, string $label, bool $allowBlank = true) use ($strict): ?float {
			$v = trim(str_replace([',', '$', '%'], '', (string) $v));
			if ($v === '') {
				return $allowBlank ? null : 0.0;
			}
			if (!is_numeric($v)) {
				if ($strict) {
					throw new \InvalidArgumentException($label . ' must be a number.');
				}
				return null;
			}
			return (float) $v;
		};

		$f = [];
		$f['name'] = trim((string) ($post['name'] ?? '')) ?: $d['name'];
		$f['install_type'] = array_key_exists((string) ($post['install_type'] ?? ''), Projects::INSTALL_TYPES) ? $post['install_type'] : null;
		$f['inverter_internet'] = array_key_exists((string) ($post['inverter_internet'] ?? ''), Projects::INVERTER_INTERNET) ? $post['inverter_internet'] : null;
		$f['tax_exempt'] = empty($post['tax_exempt']) ? 0 : 1;
		$f['tax_rate'] = ($num($post['tax_rate'] ?? '', 'Tax rate') ?? 0) / 100;
		$f['tax_note'] = trim((string) ($post['tax_note'] ?? '')) ?: null;
		$f['markup'] = ($num($post['markup'] ?? '', 'Markup') ?? 0) / 100;
		$floor = Presales::num('markup_floor');
		if ($strict && $f['markup'] < $floor - 0.0000001) {
			throw new \InvalidArgumentException('Markup can\'t go below ' . Presales::pct($floor) . ', the floor on the Rates page.');
		}
		$f['notes'] = trim((string) ($post['notes'] ?? '')) ?: null;

		// Commission: one of the rates on the Rates page (its adjusted % and step-down come with it)
		$rate = $num($post['commission_rate'] ?? '', 'Commission');
		if ($rate !== null && abs($rate / 100 - (float) $d['commission_rate']) > 0.000001) {
			$c = Db::one('SELECT * FROM presales_commission_rates WHERE ABS(rate - ?) < 0.000001', [$rate / 100]);
			if (!$c) {
				throw new \InvalidArgumentException('Pick a commission rate from the list.');
			}
			$f['commission_rate'] = (float) $c['rate'];
			$f['commission_adjusted'] = (float) $c['adjusted'];
			$f['commission_step_down'] = (float) $c['step_down'];
		} else {
			$f['commission_rate'] = (float) $d['commission_rate'];
			$f['commission_adjusted'] = (float) $d['commission_adjusted'];
			$f['commission_step_down'] = (float) $d['commission_step_down'];
		}

		// Warranty program: keep the saved price unless the program changes
		$wid = (int) ($post['warranty_item_id'] ?? 0);
		if ($wid === (int) $d['warranty_item_id']) {
			foreach (['warranty_item_id', 'warranty_price', 'warranty_name', 'warranty_is_solarinsure'] as $k) {
				$f[$k] = $d[$k];
			}
		} elseif ($wid && isset($catalog[$wid]) && $catalog[$wid]['category'] === 'warranty') {
			$f['warranty_item_id'] = $wid;
			$f['warranty_price'] = (float) $catalog[$wid]['price'];
			$f['warranty_name'] = $catalog[$wid]['name'];
			$f['warranty_is_solarinsure'] = (int) $catalog[$wid]['is_solarinsure'];
		} else {
			$f['warranty_item_id'] = null;
			$f['warranty_price'] = null;
			$f['warranty_name'] = null;
			$f['warranty_is_solarinsure'] = 0;
		}

		$f['labor_mode'] = ($post['labor_mode'] ?? '') === 'total' ? 'total' : 'per_watt';
		$f['labor_value'] = $num($post['labor_value'] ?? '', 'Labor price') ?? 0;
		if ($strict && $f['labor_value'] <= 0) {
			throw new \InvalidArgumentException('Labor price can\'t be zero. The default per watt is on the Rates page.');
		}
		$f['labor_rate'] = $num($post['labor_rate'] ?? '', 'Labor rate') ?? 0;
		$f['site_visit'] = $num($post['site_visit'] ?? '', 'Site visit') ?? 0;
		// Travel: lodging for every night away, per diem for every day away (nights + 1)
		$f['trip_nights'] = max(0, (int) ($num($post['trip_nights'] ?? '', 'Nights away') ?? 0));
		$f['trip_crew'] = max(0, (int) ($num($post['trip_crew'] ?? '', 'Crew traveling') ?? 0));
		$f['lodging_rate'] = $num($post['lodging_rate'] ?? '', 'Lodging rate') ?? 0;
		$f['per_diem_rate'] = $num($post['per_diem_rate'] ?? '', 'Per diem rate') ?? 0;
		$f['overnight'] = $f['trip_nights'] * $f['trip_crew'] * $f['lodging_rate'];
		$f['per_diem'] = ($f['trip_nights'] > 0 ? $f['trip_nights'] + 1 : 0) * $f['trip_crew'] * $f['per_diem_rate'];

		$manual = array_values(array_unique(array_map('intval', (array) ($post['contingency_manual'] ?? []))));
		$f['contingency_manual'] = json_encode($manual);
		$override = $num($post['contingency_override'] ?? '', 'Contingency override');
		$reason = trim((string) ($post['contingency_reason'] ?? ''));
		if ($override !== null && $reason === '' && $strict) {
			throw new \InvalidArgumentException('Type a reason before overriding the contingency.');
		}
		if ($override !== null && ($override < 0 || $override > 100) && $strict) {
			throw new \InvalidArgumentException('Contingency override must be between 0 and 100%.');
		}
		$f['contingency_override'] = $override;
		$f['contingency_reason'] = $override !== null ? ($reason ?: null) : null;

		$deposit = $num($post['deposit'] ?? '', 'Deposit');
		$f['deposit'] = $deposit;
		$tplId = (int) ($post['payment_template_id'] ?? 0);
		if ($tplId) {
			$t = Db::one('SELECT * FROM presales_payment_templates WHERE id = ?', [$tplId]);
			if ($t) {
				$f['payment_milestones'] = $t['milestones'];
				$f['payment_template_name'] = $t['name'];
			}
		}
		if (!isset($f['payment_milestones'])) {
			$f['payment_milestones'] = $d['payment_milestones'];
			$f['payment_template_name'] = $d['payment_template_name'];
		}

		// Lines, category by category, in the order shown
		$lines = [];
		$sort = 0;
		foreach ((array) ($post['lines'] ?? []) as $cat => $rows) {
			if (!isset(Presales::CATEGORIES[$cat]) || in_array($cat, Presales::NOT_LINES, true)) {
				continue;
			}
			foreach ((array) $rows as $r) {
				$itemKey = (string) ($r['item'] ?? '');
				$qty = $num($r['qty'] ?? '', 'Quantity');
				$lineId = (int) ($r['id'] ?? 0);
				if ($itemKey === 'custom' || ($itemKey === '' && trim((string) ($r['name'] ?? '')) !== '')) {
					$name = trim((string) ($r['name'] ?? ''));
					if ($name === '') {
						continue;
					}
					$tax = array_key_exists((string) ($r['tax_class'] ?? ''), Presales::TAX_CLASSES) ? $r['tax_class'] : 'bos';
					$lines[] = ['id' => $lineId ?: null, 'category' => $cat, 'catalog_item_id' => null, 'name' => $name, 'qty' => $qty ?? 1,
						'qty_rule' => 'manual', 'price' => $num($r['price'] ?? '', 'Price for ' . $name) ?? 0, 'shipping' => 0, 'setup_cost' => 0,
						'min_total' => null, 'tax_class' => $tax, 'watts' => $cat === 'modules' ? $num($r['watts'] ?? '', 'Watts') : null,
						'ac_kw' => null, 'kwh' => null, 'kw' => null, 'ext_warranty' => 0, 'ext_warranty_years' => null,
						'ext_warranty_cost' => null, 'siw_cost' => null, 'warranty_text' => null, 'price_date' => date('Y-m-d'),
						'note' => trim((string) ($r['note'] ?? '')) ?: null, 'sort_order' => ++$sort, '_key' => (string) ($r['key'] ?? '')];
					continue;
				}
				$itemId = (int) $itemKey;
				if (!$itemId || !isset($catalog[$itemId])) {
					continue;
				}
				$old = $existing[$lineId] ?? null;
				if ($old && (int) $old['catalog_item_id'] === $itemId) {
					$snap = array_intersect_key($old, array_flip([...self::SNAPSHOT, 'category', 'catalog_item_id', 'price_date']));
				} else {
					$snap = self::snapshot($catalog[$itemId]);
				}
				$snap['category'] = $cat;
				$ovr = $num($r['override'] ?? '', 'Price override for ' . $snap['name']);
				$why = trim((string) ($r['override_reason'] ?? ''));
				if ($ovr !== null && $strict && $why === '') {
					throw new \InvalidArgumentException('Type a reason for the price override on ' . $snap['name'] . '.');
				}
				if ($ovr !== null && $strict && $ovr < 0) {
					throw new \InvalidArgumentException('The price override on ' . $snap['name'] . ' can\'t be below zero.');
				}
				$lines[] = $snap + [
					'price_override' => $ovr,
					'override_reason' => $ovr !== null ? ($why ?: null) : null,
					'id' => $old ? $lineId : null,
					'qty' => $snap['qty_rule'] === 'manual' ? ($qty ?? 0) : null,
					'ext_warranty' => !empty($r['ext']) && $snap['ext_warranty_cost'] !== null ? 1 : 0,
					'note' => trim((string) ($r['note'] ?? '')) ?: null,
					'sort_order' => ++$sort,
					'_key' => (string) ($r['key'] ?? ''),
				];
			}
		}
		foreach ((array) ($post['extras'] ?? []) as $r) {
			$name = trim((string) ($r['name'] ?? ''));
			$amount = $num($r['amount'] ?? '', 'Extra amount');
			if ($name === '' && $amount === null) {
				continue;
			}
			$lines[] = ['id' => (int) ($r['id'] ?? 0) ?: null, 'category' => 'extra', 'catalog_item_id' => null, 'name' => $name ?: 'Extra',
				'qty' => 1, 'qty_rule' => 'manual', 'price' => $amount ?? 0, 'shipping' => 0, 'setup_cost' => 0, 'min_total' => null,
				'tax_class' => 'none', 'watts' => null, 'ac_kw' => null, 'kwh' => null, 'kw' => null, 'ext_warranty' => 0,
				'ext_warranty_years' => null, 'ext_warranty_cost' => null, 'siw_cost' => null, 'warranty_text' => null,
				'price_date' => null, 'note' => null, 'sort_order' => ++$sort];
		}

		$arrays = [];
		foreach ((array) ($post['arrays'] ?? []) as $i => $a) {
			$name = trim((string) ($a['name'] ?? ''));
			$panels = $num($a['panels'] ?? '', 'Panels');
			$tiltRaw = trim((string) ($a['tilt'] ?? ''));
			$az = $num($a['azimuth'] ?? '', 'Azimuth');
			if ($name === '' && $panels === null && $tiltRaw === '' && $az === null) {
				continue;
			}
			$mount = array_key_exists((string) ($a['mount'] ?? ''), Presales::MOUNTS) ? $a['mount'] : 'roof';
			try {
				$tilt = $mount === 'tracker' ? null : Projects::parseTilt($tiltRaw);
			} catch (\InvalidArgumentException $e) {
				if ($strict) {
					throw $e;
				}
				$tilt = null;
			}
			if ($az !== null && ($az < 0 || $az > 359) && $strict) {
				throw new \InvalidArgumentException('Azimuth must be 0 to 359 degrees.');
			}
			$arrays[] = ['name' => $name ?: null, 'mount' => $mount,
				'material' => $mount === 'roof' && array_key_exists((string) ($a['material'] ?? ''), Projects::ROOF_MATERIALS) ? $a['material'] : null,
				'azimuth' => $mount === 'tracker' || $az === null ? null : (int) $az, 'tilt' => $tilt,
				'panels' => $panels === null ? null : (int) $panels, 'sort_order' => count($arrays) + 1];
		}

		// Trench builder: segments (keys stay as the page made them) and the circuits in them
		$trenches = [];
		$keys = [];
		foreach ((array) ($post['trenches'] ?? []) as $t) {
			$key = (string) ($t['key'] ?? '');
			$name = trim((string) ($t['name'] ?? ''));
			$len = $num($t['length'] ?? '', 'Trench length');
			if ($name === '' && $len === null) {
				continue;
			}
			if (!preg_match('/^[a-z0-9]{1,16}$/', $key) || isset($keys[$key])) {
				$key = 't' . (count($trenches) + 1) . 'x' . substr(md5($name . count($trenches)), 0, 4);
			}
			if ($len !== null && $len < 0 && $strict) {
				throw new \InvalidArgumentException('Trench length can\'t be negative.');
			}
			$keys[$key] = true;
			$trenches[] = ['key' => $key, 'name' => $name ?: null, 'method' => array_key_exists((string) ($t['method'] ?? ''), Trench::METHODS) ? $t['method'] : 'open',
				'length_ft' => $len === null ? null : max(0.0, $len), 'sort_order' => count($trenches) + 1];
		}
		$circuits = [];
		foreach ((array) ($post['circuits'] ?? []) as $c) {
			$name = trim((string) ($c['name'] ?? ''));
			$kind = array_key_exists((string) ($c['kind'] ?? ''), Trench::KINDS) ? $c['kind'] : 'ac';
			$segs = array_values(array_unique(array_filter(array_map('strval', (array) ($c['segs'] ?? [])), static fn ($k) => isset($keys[$k]))));
			$kw = $num($c['ac_kw'] ?? '', 'Circuit kW');
			$strings = $num($c['strings'] ?? '', 'Strings');
			if ($name === '' && !$segs && $kw === null && $strings === null) {
				continue;
			}
			$size = in_array((string) ($c['size'] ?? ''), Trench::SIZES, true) ? (string) $c['size'] : null;
			$why = trim((string) ($c['size_reason'] ?? ''));
			if ($size !== null && $why === '' && $strict) {
				throw new \InvalidArgumentException('Type a reason for the wire size override on ' . ($name ?: 'a circuit') . '.');
			}
			$key = preg_match('/^[a-z0-9]{1,16}$/', (string) ($c['key'] ?? '')) ? (string) $c['key'] : 'c' . (count($circuits) + 1);
			$circuits[] = ['key' => $key, 'name' => $name ?: null, 'kind' => $kind,
				'ac_kw' => $kind === 'ac' && $kw !== null ? max(0.0, $kw) : null,
				'voltage' => $kind === 'ac' && array_key_exists((string) ($c['voltage'] ?? ''), Trench::VOLTAGES) ? $c['voltage'] : ($kind === 'ac' ? '240' : null),
				'strings' => $kind === 'dc' && $strings !== null ? max(0, (int) $strings) : null,
				'segments' => $segs, 'size_override' => $kind === 'comms' ? null : $size, 'override_reason' => $size !== null && $kind !== 'comms' ? ($why ?: null) : null,
				'sort_order' => count($circuits) + 1];
		}
		// A build sheet keeps the trench settings and part prices it was first priced with
		$f['trench_basis'] = $d['trench_basis'] ?? null;

		return [$f, $lines, $arrays, ['trenches' => $trenches, 'circuits' => $circuits]];
	}

	/** Save a build sheet read by fromInput(); lines, arrays and trench rows are replaced as a set. */
	public static function save(array $d, array $f, array $lines, array $arrays, array $calc, array $trench): void
	{
		Db::transaction(static function () use ($d, $f, $lines, $arrays, $calc, $trench): void {
			$f['trench_basis'] = $trench['trenches'] || $trench['circuits'] ? json_encode($calc['trench']['basis']) : null;
			$f['price_total'] = $calc['price'];
			$f['dc_watts'] = $calc['dc_watts'];
			$f['updated_by'] = Auth::id();
			$f['updated_at'] = now_utc();
			Db::update('presales_designs', (int) $d['id'], $f);
			Db::run('DELETE FROM presales_design_lines WHERE design_id = ?', [(int) $d['id']]);
			foreach ($lines as $l) {
				unset($l['id'], $l['_key']);
				$l['design_id'] = (int) $d['id'];
				Db::insert('presales_design_lines', $l);
			}
			Db::run('DELETE FROM presales_design_arrays WHERE design_id = ?', [(int) $d['id']]);
			foreach ($arrays as $a) {
				$a['design_id'] = (int) $d['id'];
				Db::insert('presales_design_arrays', $a);
			}
			Db::run('DELETE FROM presales_design_trenches WHERE design_id = ?', [(int) $d['id']]);
			foreach ($trench['trenches'] as $t) {
				Db::insert('presales_design_trenches', $t + ['design_id' => (int) $d['id']]);
			}
			Db::run('DELETE FROM presales_design_circuits WHERE design_id = ?', [(int) $d['id']]);
			foreach ($trench['circuits'] as $c) {
				$c['segments'] = json_encode(array_values($c['segments']));
				Db::insert('presales_design_circuits', $c + ['design_id' => (int) $d['id']]);
			}
			Db::update('presales_opportunities', (int) $d['opportunity_id'], ['updated_at' => now_utc()]);
		});
	}

	/**
	 * Run the pricing for a design and its lines (rows from the database or from fromInput). The
	 * trench builder's parts join the lines; it needs the build sheet's AC kW, so a first pass
	 * without them finds that.
	 */
	public static function calc(array $d, array $lines, array $arrays, ?array $trench = null): array
	{
		$trench ??= isset($d['id']) ? self::trench((int) $d['id']) : ['trenches' => [], 'circuits' => []];
		$rules = Presales::contingencyRules();
		$round = max(1, Presales::num('round_to'));
		$first = Pricing::calculate($d, $lines, $arrays, $rules, $round);
		$basis = json_decode((string) ($d['trench_basis'] ?? ''), true) ?: [];
		$tr = Trench::calculate($trench['trenches'], $trench['circuits'], $basis, (float) $first['ac_kw']);
		$calc = $tr['lines'] ? Pricing::calculate($d, array_merge($lines, $tr['lines']), $arrays, $rules, $round) : $first;
		$calc['trench'] = $tr;
		$calc['trench_rows'] = $trench;
		$calc['warranty_summary'] = Pricing::warrantySummary($d, $calc['lines'], Presales::setting('trifecta_warranty_text'), Presales::setting('solarinsure_text'));
		$calc['checks'] = self::checks($d, $calc, $arrays);
		return $calc;
	}

	/**
	 * Equipment checks shown on the build sheet (warnings, never blocking):
	 * an inverter that needs optimizers with no optimizer line, and rooftop arrays with no
	 * optimizer, microinverter or rapid shutdown line (ground mounts may not need rapid shutdown).
	 */
	public static function checks(array $d, array $calc, array $arrays): array
	{
		$hasMlpe = false;
		$needs = [];
		// the catalog's current flag counts too, for lines picked before the flag existed
		$ids = array_filter(array_map(static fn ($l) => $l['category'] === 'inverters' ? (int) ($l['catalog_item_id'] ?? 0) : 0, $calc['lines']));
		$flagged = $ids ? array_map('intval', array_column(Db::all('SELECT id FROM catalog_items WHERE needs_mlpe = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)), 'id')) : [];
		foreach ($calc['lines'] as $l) {
			if (($l['qty_resolved'] ?? 0) <= 0) {
				continue;
			}
			if ($l['category'] === 'mlpe') {
				$hasMlpe = true;
			}
			if ($l['category'] === 'inverters' && (!empty($l['needs_mlpe']) || in_array((int) ($l['catalog_item_id'] ?? 0), $flagged, true))) {
				$needs[] = $l['name'];
			}
		}
		$out = [];
		if ($needs && !$hasMlpe) {
			$out[] = implode(', ', array_unique($needs)) . ' needs an optimizer on every module. Add one under Optimizers, micros and rapid shutdown.';
		}
		$roof = ($d['install_type'] ?? '') === 'roof' || in_array('roof', array_column($arrays, 'mount'), true);
		if ($roof && !$hasMlpe && !$needs && $calc['panels'] > 0) {
			$out[] = 'Rooftop arrays need rapid shutdown. Add an optimizer, microinverter or rapid shutdown line.';
		}
		return $out;
	}

	/**
	 * Price checks per line: 'changed' when today's catalog differs from the line's snapshot,
	 * 'stale' when the catalog price behind the line is older than the stale setting,
	 * 'retired' when the catalog item is gone or inactive.
	 */
	public static function priceFlags(array $lines, ?array $design = null): array
	{
		$ids = array_filter(array_map(static fn ($l) => (int) ($l['catalog_item_id'] ?? 0), $lines));
		if ($design && $design['warranty_item_id']) {
			$ids[] = (int) $design['warranty_item_id'];
		}
		$catalog = [];
		if ($ids) {
			$ph = implode(',', array_fill(0, count($ids), '?'));
			foreach (Db::all("SELECT * FROM catalog_items WHERE id IN ($ph)", array_values($ids)) as $c) {
				$catalog[(int) $c['id']] = $c;
			}
		}
		$flags = [];
		foreach ($lines as $i => $l) {
			$id = (int) ($l['catalog_item_id'] ?? 0);
			if (!$id) {
				continue;
			}
			$c = $catalog[$id] ?? null;
			$f = ['changed' => false, 'stale' => false, 'retired' => false, 'catalog' => $c];
			if (!$c || !$c['is_active']) {
				$f['retired'] = true;
			} else {
				foreach (self::PRICE_FIELDS as $k) {
					if ((string) ($c[$k] ?? '') !== (string) ($l[$k] ?? '') && !(is_numeric($c[$k]) && is_numeric($l[$k]) && abs((float) $c[$k] - (float) $l[$k]) < 0.00001)) {
						$f['changed'] = true;
					}
				}
				$f['stale'] = Presales::isStale($c['price_updated_at']);
			}
			$flags[$i] = $f;
		}
		$warranty = null;
		if ($design && $design['warranty_item_id']) {
			$c = $catalog[(int) $design['warranty_item_id']] ?? null;
			$warranty = ['changed' => $c && abs((float) $c['price'] - (float) $design['warranty_price']) > 0.00001, 'retired' => !$c || !$c['is_active'],
				'stale' => $c ? Presales::isStale($c['price_updated_at']) : false];
		}
		return ['lines' => $flags, 'warranty' => $warranty,
			'changed' => count(array_filter($flags, static fn ($f) => $f['changed'])) + (!empty($warranty['changed']) ? 1 : 0),
			'stale' => count(array_filter($flags, static fn ($f) => $f['stale'])),
			'retired' => count(array_filter($flags, static fn ($f) => $f['retired']))];
	}

	/** Trench builder parts whose catalog price is different now than this build sheet's (part keys). */
	public static function trenchChanged(array $calc): array
	{
		$keys = array_column($calc['trench']['lines'] ?? [], 'trench_key');
		return $keys ? Trench::changedParts($calc['trench']['basis'], $keys) : [];
	}

	/** Bring every catalog line (and the warranty program) up to today's catalog prices. */
	public static function reprice(array $d): int
	{
		$count = 0;
		foreach (self::lines((int) $d['id']) as $l) {
			if (!$l['catalog_item_id']) {
				continue;
			}
			$c = Db::one('SELECT * FROM catalog_items WHERE id = ? AND is_active = 1', [(int) $l['catalog_item_id']]);
			if (!$c) {
				continue;
			}
			$snap = self::snapshot($c);
			unset($snap['category']);
			if ($snap['ext_warranty_cost'] === null) {
				$snap['ext_warranty'] = 0;
			}
			Db::update('presales_design_lines', (int) $l['id'], $snap);
			$count++;
		}
		Db::update('presales_designs', (int) $d['id'], ['trench_basis' => null]);
		if ($d['warranty_item_id']) {
			$c = Db::one('SELECT * FROM catalog_items WHERE id = ? AND is_active = 1', [(int) $d['warranty_item_id']]);
			if ($c) {
				Db::update('presales_designs', (int) $d['id'], ['warranty_price' => (float) $c['price'], 'warranty_name' => $c['name'],
					'warranty_is_solarinsure' => (int) $c['is_solarinsure']]);
			}
		}
		$fresh = self::find((int) $d['id']);
		$calc = self::calc($fresh, self::lines((int) $d['id']), self::arrays((int) $d['id']));
		$trench = self::trench((int) $d['id']);
		Db::update('presales_designs', (int) $d['id'], ['price_total' => $calc['price'], 'dc_watts' => $calc['dc_watts'],
			'trench_basis' => $trench['trenches'] || $trench['circuits'] ? json_encode($calc['trench']['basis']) : null,
			'updated_by' => Auth::id(), 'updated_at' => now_utc()]);
		return $count;
	}
}
