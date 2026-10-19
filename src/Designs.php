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
		'kwh', 'kw', 'ext_warranty_years', 'ext_warranty_cost', 'siw_cost', 'warranty_text', 'domestic_content_pct', 'feoc_ok', 'needs_mlpe', 'pricing', 'pays_sales',
		'cec_name', 'cec_units', 'spec', 'degradation_first_pct', 'degradation_annual_pct', 'product_warranty_years', 'performance_warranty_years',
		'length_mm', 'width_mm'];
	/** The fields that make a line's price differ from the catalog today. */
	private const PRICE_FIELDS = ['price', 'shipping', 'setup_cost', 'min_total', 'ext_warranty_cost', 'siw_cost', 'tax_class', 'pricing', 'pays_sales'];

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
		$template = Db::one('SELECT * FROM presales_payment_templates ORDER BY is_default DESC, sort_order LIMIT 1');
		$warranty = Db::one("SELECT * FROM catalog_items WHERE category = 'warranty' AND is_active = 1 ORDER BY is_solarinsure, sort_order LIMIT 1");
		$sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM presales_designs WHERE opportunity_id = ?', [$oppId]);
		$id = Db::insert('presales_designs', [
			'opportunity_id' => $oppId,
			'name' => $name,
			'tax_rate' => Presales::num('tax_rate'),
			'tax_note' => Presales::setting('tax_note'),
			'markup' => Presales::num('markup_default'),
			'commission_plan' => json_encode(Commission::currentPlan()),
			'warranty_item_id' => $warranty['id'] ?? null,
			'warranty_price' => $warranty ? (float) $warranty['price'] : null,
			'warranty_name' => $warranty['name'] ?? null,
			'warranty_is_solarinsure' => (int) ($warranty['is_solarinsure'] ?? 0),
			'labor_mode' => 'per_watt',
			'labor_value' => Presales::num('labor_default'),
			'labor_rate' => Presales::num('labor_rate'),
			'lodging_rate' => Presales::num('lodging_rate'),
			'per_diem_rate' => Presales::num('per_diem_rate'),
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
			// A copy is a new proposal: its own quote number and dates; the guarantee isn't copied
			$row['quote_number'] = null;
			$row['proposal_date'] = null;
			$row['valid_until'] = null;
			$row['guarantee_on'] = 0;
			$row['guarantee_by'] = null;
			$row['guarantee_at'] = null;
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

	/** Quantities are whole numbers, except per-foot categories and Other. */
	public static function fractionsOk(string $category): bool
	{
		return (Presales::CATEGORIES[$category][1] ?? 'ea') === 'ft' || $category === 'misc';
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
		$f += self::proposalInput($d, $post, $num, $strict);

		// Commission: build sheets with a plan keep it; older ones pick one of the old single rates
		$rate = empty($d['commission_plan']) ? $num($post['commission_rate'] ?? '', 'Commission') : null;
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
		// Site visit is a catalog line now; only build sheets from before commission plans keep the field
		$f['site_visit'] = empty($d['commission_plan']) ? ($num($post['site_visit'] ?? '', 'Site visit') ?? 0) : (float) $d['site_visit'];
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
				if ($qty !== null && floor($qty) != $qty && !self::fractionsOk($cat)) {
					if ($strict) {
						throw new \InvalidArgumentException(Presales::CATEGORIES[$cat][0] . ' take whole numbers (' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' was typed).');
					}
					$qty = round($qty);
				}
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
						'note' => trim((string) ($r['note'] ?? '')) ?: null, 'pricing' => 'markup', 'pays_sales' => 0,
						'sort_order' => ++$sort, '_key' => (string) ($r['key'] ?? '')];
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

		// Once the site layout has panels it is the master: module counts and arrays come from it
		$layout = Layout::decode($d['layout_json'] ?? null);
		$lines = Layout::applyCounts($layout, $lines);
		$master = Layout::panels($layout) > 0;

		$arrays = [];
		// Arrays drawn on the site layout keep what the drawing says
		$drawn = [];
		foreach (isset($d['id']) ? Db::all('SELECT * FROM presales_design_arrays WHERE design_id = ? AND layout_key IS NOT NULL ORDER BY sort_order, id', [(int) $d['id']]) : [] as $r) {
			$drawn[$r['layout_key']] = $r;
		}
		$drawnRow = static function (array $r, array $a = []) use (&$arrays, $strict, $num): array {
			$shade = $num($a['shading_pct'] ?? '', 'Shading');
			if ($shade !== null && ($shade < 0 || $shade > 90)) {
				if ($strict) {
					throw new \InvalidArgumentException('Shading is a percent loss, 0 to 90.');
				}
				$shade = null;
			}
			$note = trim((string) ($a['shading_note'] ?? ''));
			return ['name' => $r['name'], 'mount' => $r['mount'],
				'material' => $r['mount'] === 'roof' && array_key_exists((string) ($a['material'] ?? ''), Projects::ROOF_MATERIALS) ? $a['material'] : ($a ? null : $r['material']),
				'azimuth' => $r['azimuth'], 'tilt' => $r['tilt'], 'panels' => $r['panels'],
				'shading_pct' => $a ? $shade : $r['shading_pct'], 'shading_note' => $a ? ($note !== '' ? mb_substr($note, 0, 200) : null) : $r['shading_note'],
				'layout_key' => $r['layout_key'], 'sort_order' => count($arrays) + 1];
		};
		foreach ($master ? [] : (array) ($post['arrays'] ?? []) as $i => $a) {
			$lk = (string) ($a['layout_key'] ?? '');
			if ($lk !== '' && isset($drawn[$lk])) {
				$arrays[] = $drawnRow($drawn[$lk], $a);
				unset($drawn[$lk]);
				continue;
			}
			$name = trim((string) ($a['name'] ?? ''));
			$panels = $num($a['panels'] ?? '', 'Panels');
			$tiltRaw = trim((string) ($a['tilt'] ?? ''));
			$az = $num($a['azimuth'] ?? '', 'Azimuth');
			$shade = $num($a['shading_pct'] ?? '', 'Shading');
			$shadeNote = trim((string) ($a['shading_note'] ?? ''));
			if ($name === '' && $panels === null && $tiltRaw === '' && $az === null && $shade === null && $shadeNote === '') {
				continue;
			}
			if ($shade !== null && ($shade < 0 || $shade > 90)) {
				if ($strict) {
					throw new \InvalidArgumentException('Shading is a percent loss, 0 to 90.');
				}
				$shade = null;
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
				'panels' => $panels === null ? null : (int) $panels,
				'shading_pct' => $shade, 'shading_note' => $shadeNote !== '' ? mb_substr($shadeNote, 0, 200) : null,
				'sort_order' => count($arrays) + 1];
		}
		foreach ($drawn as $r) {   // drawn arrays not on the form stay as they are
			$arrays[] = $drawnRow($r);
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

	/** Longest customer notes on a proposal (they must fit on the System Summary page). */
	public const NOTES_MAX = 800;

	/**
	 * The build sheet's proposal and savings fields: the production estimate it leads with, its own
	 * savings assumptions, incentives, financing, the contact, dates and customer notes. The
	 * production guarantee changes only for admins.
	 */
	private static function proposalInput(array $d, array $post, callable $num, bool $strict): array
	{
		$fail = static function (string $msg) use ($strict): void {
			if ($strict) {
				throw new \InvalidArgumentException($msg);
			}
		};
		$f = [];
		$model = (string) ($post['production_model'] ?? '');
		$f['production_model'] = isset(Production::MODELS[$model]) ? $model : null;

		$own = [];
		foreach (Savings::ASSUMPTIONS as $k => [, $label, $unit, $low, $high]) {
			$v = $num($post['savings'][$k] ?? '', $label);
			if ($v === null) {
				continue;
			}
			if ($v < $low || $v > $high) {
				$fail($label . ' must be ' . $low . ' to ' . $high . ' (' . $unit . ').');
				continue;
			}
			$own[$k] = $v;
		}
		$dep = (string) ($post['savings']['dep'] ?? '');
		if (in_array($dep, ['on', 'off'], true)) {
			$own['dep'] = $dep;
		}
		$method = (string) ($post['savings']['dep_method'] ?? '');
		// Kept only when it differs from the Rates page, so the build sheet follows the default otherwise
		if (isset(Savings::DEP_METHODS[$method]) && $method !== Savings::depreciationMethod(['savings_inputs' => null])) {
			$own['dep_method'] = $method;
		}
		$f['savings_inputs'] = $own ? json_encode($own) : null;

		$inc = [];
		foreach ((array) ($post['incentives'] ?? []) as $r) {
			$label = trim((string) ($r['label'] ?? ''));
			$v = $num($r['value'] ?? '', 'Incentive amount');
			if ($label === '' && $v === null) {
				continue;
			}
			$kind = ($r['kind'] ?? '') === 'pct' ? 'pct' : 'amount';
			if ($v === null || $v <= 0 || ($kind === 'pct' && $v > 100)) {
				$fail('Each incentive needs an amount (dollars, or a percent of the price up to 100).');
				continue;
			}
			$inc[] = ['label' => mb_substr($label ?: 'Incentive', 0, 80), 'kind' => $kind, 'value' => $v,
				'timing' => ($r['timing'] ?? '') === 'year1' ? 'year1' : 'upfront'];
		}
		$f['incentives'] = $inc ? json_encode($inc) : null;

		$f['finance_down'] = $num($post['finance_down'] ?? '', 'Down payment');
		$f['finance_rate'] = $num($post['finance_rate'] ?? '', 'Interest rate');
		$years = $num($post['finance_years'] ?? '', 'Loan term');
		$f['finance_years'] = $years === null ? null : (int) round($years);
		if ($f['finance_rate'] !== null && ($f['finance_rate'] < 0 || $f['finance_rate'] > 40)) {
			$fail('The loan rate is a yearly percent, 0 to 40.');
		}
		if ($f['finance_years'] !== null && ($f['finance_years'] < 1 || $f['finance_years'] > 30)) {
			$fail('The loan term is 1 to 30 years.');
		}

		$notes = trim(str_replace("\r\n", "\n", (string) ($post['customer_notes'] ?? '')));
		if (mb_strlen($notes) > self::NOTES_MAX) {
			$fail('Notes for the customer can be at most ' . self::NOTES_MAX . ' characters (they must fit on the System Summary page).');
			$notes = mb_substr($notes, 0, self::NOTES_MAX);
		}
		$f['customer_notes'] = $notes !== '' ? $notes : null;

		$contact = (int) ($post['contact_id'] ?? 0);
		$f['contact_id'] = $contact && Db::value("SELECT 1 FROM contacts c JOIN presales_opportunities o ON o.customer_id = c.owner_id
			WHERE c.id = ? AND c.owner_type = 'organization' AND o.id = ?", [$contact, (int) $d['opportunity_id']]) ? $contact : null;

		$date = static function (string $raw, string $label) use ($fail): ?string {
			$raw = trim($raw);
			if ($raw === '') {
				return null;
			}
			$t = \DateTime::createFromFormat('!m/d/Y', $raw) ?: \DateTime::createFromFormat('!Y-m-d', $raw);
			if (!$t) {
				$fail($label . ': type a date like ' . date('m/d/Y') . '.');
				return null;
			}
			return $t->format('Y-m-d');
		};
		$f['proposal_date'] = $date((string) ($post['proposal_date'] ?? ''), 'Proposal date');
		$f['valid_until'] = $date((string) ($post['valid_until'] ?? ''), 'Valid until');
		if ($f['valid_until'] !== null) {
			$from = $f['proposal_date'] ?? date('Y-m-d');
			$max = (int) Presales::num('proposal_valid_max');
			if ($f['valid_until'] < $from) {
				$fail('Valid until can\'t be before the proposal date.');
			} elseif ((strtotime($f['valid_until']) - strtotime($from)) / 86400 > $max) {
				$fail('Valid until can be at most ' . $max . ' days after the proposal date.');
			}
		}

		$f['guarantee_on'] = (int) ($d['guarantee_on'] ?? 0);
		if (Auth::isAdmin() && array_key_exists('guarantee_present', $post)) {
			$on = empty($post['guarantee_on']) ? 0 : 1;
			if ($on !== $f['guarantee_on']) {
				$f['guarantee_on'] = $on;
				$f['guarantee_by'] = Auth::id();
				$f['guarantee_at'] = now_utc();
			}
		}
		return $f;
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
	 * trench builder's parts join the lines.
	 */
	public static function calc(array $d, array $lines, array $arrays, ?array $trench = null): array
	{
		$d += self::leadFacts((int) ($d['opportunity_id'] ?? 0));
		$trench ??= isset($d['id']) ? self::trench((int) $d['id']) : ['trenches' => [], 'circuits' => []];
		$rules = Presales::contingencyRules();
		$round = max(1, Presales::num('round_to'));
		$basis = json_decode((string) ($d['trench_basis'] ?? ''), true) ?: [];
		$tr = Trench::calculate($trench['trenches'], $trench['circuits'], $basis);
		$calc = Pricing::calculate($d, array_merge($lines, $tr['lines']), $arrays, $rules, $round);
		$calc['trench'] = $tr;
		$calc['trench_rows'] = $trench;
		$calc['warranty_summary'] = Pricing::warrantySummary($d, $calc['lines'], Presales::setting('trifecta_warranty_text'), Presales::setting('solarinsure_text'));
		$calc['checks'] = self::checks($d, $calc, $arrays);
		return $calc;
	}

	/**
	 * What the pricing needs to know about the opportunity: its lead source, whether there is an
	 * in-house handoff, and whether the self-generated bonus can apply (net-new customers only).
	 */
	public static function leadFacts(int $oppId): array
	{
		$o = $oppId ? Db::one('SELECT id, customer_id, lead_source, handoff_user_id, project_id FROM presales_opportunities WHERE id = ?', [$oppId]) : null;
		if (!$o) {
			return ['lead_source' => null, 'handoff_user_id' => null, 'self_gen_allowed' => true];
		}
		return ['lead_source' => $o['lead_source'], 'handoff_user_id' => $o['handoff_user_id'],
			'self_gen_allowed' => !Presales::isRepeatCustomer($o)];
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
		// Is the quote complete: the core equipment, labor, permits and interconnection
		$has = [];
		foreach ($calc['lines'] as $l) {
			if (($l['qty_resolved'] ?? 0) > 0) {
				$has[$l['category']] = true;
				if ($l['category'] === 'mlpe' && (float) ($l['ac_kw'] ?? 0) > 0) {
					$has['inverters'] = true;   // microinverters
				}
			}
		}
		$missing = [];
		foreach (['modules' => 'modules', 'inverters' => 'inverters or microinverters', 'racking' => 'racking', 'bos' => 'balance of system',
			'permits' => 'permits', 'interconnection' => 'interconnection'] as $cat => $label) {
			if (empty($has[$cat])) {
				$missing[] = $label;
			}
		}
		if ((float) ($calc['labor'] ?? 0) <= 0) {
			$missing[] = 'labor';
		}
		if ($missing) {
			$out[] = 'Nothing for ' . self::listing($missing) . ' yet. Add ' . (count($missing) === 1 ? 'it' : 'them') . ' unless this job really has none.';
		}
		if (!empty($has['modules']) && !empty($has['inverters']) && $calc['ratio'] !== null && ($calc['ratio'] > 2 || $calc['ratio'] < 0.95)) {
			$out[] = 'The DC/AC ratio is ' . number_format($calc['ratio'], 2) . ', outside 0.95 to 2. Check the inverter size.';
		}
		return $out;
	}

	/** Text for a folded header: the whole sentences that fit in $max characters, else the start cut with "...". */
	public static function gist(string $text, int $max): string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', $text));
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$out = '';
		foreach (preg_split('/(?<=[.!?])\s+/', $text) as $sentence) {
			if (mb_strlen(trim($out . ' ' . $sentence)) > $max) {
				break;
			}
			$out = trim($out . ' ' . $sentence);
		}
		return $out !== '' ? $out : mb_strimwidth($text, 0, $max, '...');
	}

	/** "a, b and c" */
	private static function listing(array $items): string
	{
		return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : (string) ($items[0] ?? '');
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
		// A copy at today's prices also moves to today's commission plan. The old site visit field
		// becomes the catalog's site visit line (a pays-the-salesperson item) when there is one.
		$planUpdate = ['commission_plan' => json_encode(Commission::currentPlan()), 'site_visit' => 0,
			'commission_rate' => 0, 'commission_adjusted' => 0, 'commission_step_down' => 0];
		if ((float) $d['site_visit'] > 0) {
			$visit = Db::one("SELECT * FROM catalog_items WHERE is_active = 1 AND pays_sales = 1 AND name LIKE '%site visit%' ORDER BY sort_order, id LIMIT 1");
			if ($visit) {
				$line = self::snapshot($visit) + ['design_id' => (int) $d['id'], 'qty' => 1, 'ext_warranty' => 0,
					'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM presales_design_lines WHERE design_id = ?', [(int) $d['id']])];
				Db::insert('presales_design_lines', $line);
			} else {
				$planUpdate['site_visit'] = (float) $d['site_visit'];
			}
		}
		Db::update('presales_designs', (int) $d['id'], $planUpdate);
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
