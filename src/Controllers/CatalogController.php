<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\CecLibrary;
use App\Db;
use App\Presales;
use App\Trench;
use App\View;

/**
 * Pre-Sales > Catalog: every product and service the build sheets price, with the date and
 * source of each price. Everyone can look; admins and salespeople edit.
 */
final class CatalogController
{
	private const TRACKED = [
		'category' => 'Category', 'name' => 'Name', 'price' => 'Price', 'shipping' => 'Shipping', 'setup_cost' => 'Per-line adder',
		'min_total' => 'Minimum', 'qty_rule' => 'Quantity', 'tax_class' => 'Tax', 'watts' => 'Watts', 'ac_kw' => 'AC kW',
		'kwh' => 'kWh', 'kw' => 'Battery kW', 'warranty_text' => 'Warranty', 'ext_warranty_years' => 'Extended warranty years',
		'ext_warranty_cost' => 'Extended warranty cost', 'siw_cost' => 'SolarInsure cost', 'is_solarinsure' => 'SolarInsure program',
		'price_source' => 'Price source', 'price_updated_at' => 'Price date', 'notes' => 'Notes', 'print_notes' => 'Print notes',
		'is_active' => 'Active', 'segment' => 'Jobs', 'mounts' => 'Install types',
		'domestic_content_pct' => 'Domestic content %', 'feoc_ok' => 'FEOC compliant', 'needs_mlpe' => 'Needs optimizers',
		'trench_part' => 'Trench builder part', 'part_size' => 'Part size', 'material' => 'Material',
		'pricing' => 'Pricing', 'pays_sales' => 'Pays the salesperson',
		'cec_name' => 'SAM (CEC) model', 'spec' => 'Spec sheet values', 'cec_units' => 'CEC units per inverter', 'degradation_first_pct' => 'First-year degradation %',
		'degradation_annual_pct' => 'Annual degradation %', 'product_warranty_years' => 'Product warranty years',
		'performance_warranty_years' => 'Performance warranty years', 'length_mm' => 'Length (mm)', 'width_mm' => 'Width (mm)',
	];

	public function index(): void
	{
		$q = trim((string) ($_GET['q'] ?? ''));
		$cat = (string) ($_GET['category'] ?? '');
		$show = (string) ($_GET['show'] ?? 'active');
		$where = [];
		$params = [];
		if ($show === 'active') {
			$where[] = 'i.is_active = 1';
		} elseif ($show === 'stale') {
			$where[] = 'i.is_active = 1';
		} elseif ($show === 'retired') {
			$where[] = 'i.is_active = 0';
		}
		if (isset(Presales::CATEGORIES[$cat])) {
			$where[] = 'i.category = ?';
			$params[] = $cat;
		}
		if ($q !== '') {
			$where[] = '(i.name LIKE ? OR i.notes LIKE ? OR i.price_source LIKE ?)';
			array_push($params, "%$q%", "%$q%", "%$q%");
		}
		$rows = Db::all('SELECT i.*, u.name AS updated_by_name FROM catalog_items i LEFT JOIN users u ON u.id = i.price_updated_by'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.sort_order, i.name', $params);
		if ($show === 'stale') {
			$rows = array_values(array_filter($rows, static fn ($r) => Presales::isStale($r['price_updated_at'])));
		}
		$grouped = [];
		foreach ($rows as $r) {
			$grouped[$r['category']][] = $r;
		}
		$ordered = [];
		foreach (array_keys(Presales::CATEGORIES) as $k) {
			if (isset($grouped[$k])) {
				$ordered[$k] = $grouped[$k];
			}
		}
		$staleCount = 0;
		foreach (Db::all('SELECT price_updated_at FROM catalog_items WHERE is_active = 1') as $r) {
			$staleCount += Presales::isStale($r['price_updated_at']) ? 1 : 0;
		}
		View::render('presales/catalog', ['title' => 'Sales catalog', 'groups' => $ordered, 'q' => $q, 'cat' => $cat, 'show' => $show,
			'staleCount' => $staleCount, 'canEdit' => Presales::canEdit(),
			'counts' => ['active' => (int) Db::value('SELECT COUNT(*) FROM catalog_items WHERE is_active = 1'), 'retired' => (int) Db::value('SELECT COUNT(*) FROM catalog_items WHERE is_active = 0')]]);
	}

	public function create(): void
	{
		Presales::requireEdit();
		$item = $_SESSION['old_catalog'] ?? ['category' => (string) ($_GET['category'] ?? 'modules'), 'qty_rule' => 'manual', 'tax_class' => 'major', 'is_active' => 1,
			'price_updated_at' => date('Y-m-d')];
		unset($_SESSION['old_catalog']);
		View::render('presales/catalog_form', ['title' => 'New catalog item', 'item' => $item, 'id' => null, 'activity' => [], 'back' => self::listBack($_GET['back'] ?? '')]);
	}

	public function edit(int $id): void
	{
		$saved = $this->find($id);
		$item = $_SESSION['old_catalog'] ?? $saved;
		unset($_SESSION['old_catalog']);
		$used = (int) Db::value('SELECT COUNT(DISTINCT design_id) FROM presales_design_lines WHERE catalog_item_id = ?', [$id]);
		View::render('presales/catalog_form', ['title' => $saved['name'], 'item' => $item, 'id' => $id, 'used' => $used,
			'activity' => Activity::feed('catalog_item', $id), 'canEdit' => Presales::canEdit(), 'back' => self::listBack($_GET['back'] ?? '')]);
	}

	public function store(): void
	{
		Presales::requireEdit();
		try {
			$data = $this->input(null, null);
			$data['price_updated_by'] = Auth::id();
			$data['sort_order'] = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_items');
			$id = Db::insert('catalog_items', $data);
			Activity::event('catalog_item', $id, 'Added');
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_catalog'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/presales/catalog/new' . self::backQuery($_POST['back'] ?? ''));
		}
		flash('success', '"' . $data['name'] . '" added.');
		redirect((self::listBack($_POST['back'] ?? '') ?: '/presales/catalog?category=' . rawurlencode($data['category'])) . '#item-' . $id);
	}

	public function update(int $id): void
	{
		Presales::requireEdit();
		$before = $this->find($id);
		try {
			$data = $this->input($id, $before);
			$data['updated_at'] = now_utc();
			Db::update('catalog_items', $id, $data);
			Activity::changes('catalog_item', $id, $before, $data, self::TRACKED, ['spec' => static fn ($v) => CecLibrary::specSummary($v)]);
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_catalog'] = $_POST + ['id' => $id];
			flash('error', $e->getMessage());
			redirect('/presales/catalog/' . $id . self::backQuery($_POST['back'] ?? ''));
		}
		flash('success', 'Saved "' . $data['name'] . '". Build sheets already using it keep their price until someone updates them.');
		redirect((self::listBack($_POST['back'] ?? '') ?: '/presales/catalog?category=' . rawurlencode($data['category'])) . '#item-' . $id);
	}

	/** The price was checked and is still right: date it today without changing it. */
	public function confirm(int $id): void
	{
		Presales::requireEdit();
		$item = $this->find($id);
		$source = trim((string) ($_POST['price_source'] ?? '')) ?: $item['price_source'];
		Db::update('catalog_items', $id, ['price_updated_at' => date('Y-m-d'), 'price_updated_by' => Auth::id(), 'price_source' => $source, 'updated_at' => now_utc()]);
		Activity::event('catalog_item', $id, 'Price confirmed at ' . Presales::price((float) $item['price'], $item['qty_rule']));
		flash('success', 'Price for "' . $item['name'] . '" confirmed as of today.');
		$back = local_path((string) ($_POST['back'] ?? ''), '/presales/catalog');
		redirect($back . '#item-' . $id);
	}

	/**
	 * The catalog list URL the person came from (search, category and tab kept), or '' when there
	 * is none. Only the catalog list itself is accepted, never another page, and any #fragment is
	 * dropped so the caller can add the item's own.
	 */
	/** SAM CEC library search for the catalog form's model picker: JSON. */
	public function cec(): void
	{
		header('Content-Type: application/json');
		$q = trim((string) ($_GET['q'] ?? ''));
		echo json_encode(mb_strlen($q) < 2 ? [] : CecLibrary::search($q, 30));
	}

	public static function listBack(mixed $back): string
	{
		$back = explode('#', is_string($back) ? $back : '', 2)[0];
		$back = local_path($back, '');
		return preg_match('#^/presales/catalog(\?[^/]*)?$#', $back) ? $back : '';
	}

	/** "?back=..." for links and redirects to an item page, or '' when there is no list to return to. */
	public static function backQuery(mixed $back): string
	{
		$back = self::listBack($back);
		return $back === '' ? '' : '?back=' . rawurlencode($back);
	}

	private function input(?int $id, ?array $before): array
	{
		$p = $_POST;
		$num = static function (string $key, string $label, bool $blankNull = true) use ($p): ?float {
			$v = trim(str_replace([',', '$'], '', (string) ($p[$key] ?? '')));
			if ($v === '') {
				return $blankNull ? null : 0.0;
			}
			if (!is_numeric($v) || (float) $v < 0) {
				throw new \InvalidArgumentException($label . ' must be a number (0 or more).');
			}
			return (float) $v;
		};
		$cat = (string) ($p['category'] ?? '');
		if (!isset(Presales::CATEGORIES[$cat])) {
			throw new \InvalidArgumentException('Pick a category.');
		}
		$name = trim(preg_replace('/\s+/', ' ', (string) ($p['name'] ?? '')) ?? '');
		if ($name === '') {
			throw new \InvalidArgumentException('Name is required.');
		}
		if (Db::value('SELECT 1 FROM catalog_items WHERE category = ? AND name = ? COLLATE NOCASE AND id <> ?', [$cat, $name, $id ?? 0])) {
			throw new \InvalidArgumentException('There is already a "' . $name . '" in ' . Presales::categoryLabel($cat) . '.');
		}
		$rule = (string) ($p['qty_rule'] ?? 'manual');
		$tax = (string) ($p['tax_class'] ?? 'bos');
		$data = [
			'category' => $cat,
			'name' => $name,
			'price' => $num('price', 'Price', false),
			'shipping' => $num('shipping', 'Shipping', false),
			'setup_cost' => $num('setup_cost', 'Per-line adder', false),
			'min_total' => $num('min_total', 'Minimum'),
			'qty_rule' => array_key_exists($rule, Presales::QTY_RULES) ? $rule : 'manual',
			'tax_class' => array_key_exists($tax, Presales::TAX_CLASSES) ? $tax : 'bos',
			'watts' => $num('watts', 'Watts'),
			'ac_kw' => $num('ac_kw', 'AC kW'),
			'kwh' => $num('kwh', 'kWh'),
			'kw' => $num('kw', 'Battery kW'),
			'warranty_text' => trim((string) ($p['warranty_text'] ?? '')) ?: null,
			'ext_warranty_years' => ($y = $num('ext_warranty_years', 'Extended warranty years')) === null ? null : (int) $y,
			'ext_warranty_cost' => $num('ext_warranty_cost', 'Extended warranty cost'),
			'siw_cost' => $num('siw_cost', 'SolarInsure cost'),
			'is_solarinsure' => $cat === 'warranty' && !empty($p['is_solarinsure']) ? 1 : 0,
			'price_source' => trim((string) ($p['price_source'] ?? '')) ?: null,
			'notes' => trim((string) ($p['notes'] ?? '')) ?: null,
			'print_notes' => empty($p['print_notes']) ? 0 : 1,
			'is_active' => empty($p['is_active']) ? 0 : 1,
			'segment' => in_array($p['segment'] ?? '', ['residential', 'commercial'], true) ? $p['segment'] : null,
			'mounts' => implode(',', array_values(array_intersect(array_keys(Presales::MOUNT_FILTERS), (array) ($p['mounts'] ?? [])))) ?: null,
			'domestic_content_pct' => $num('domestic_content_pct', 'Domestic content'),
			'needs_mlpe' => $cat === 'inverters' && !empty($p['needs_mlpe']) ? 1 : 0,
			'pricing' => ($p['pricing'] ?? '') === 'pass' ? 'pass' : 'markup',
			'pays_sales' => empty($p['pays_sales']) ? 0 : 1,
			'feoc_ok' => ($p['feoc_ok'] ?? '') === '' ? null : ((string) $p['feoc_ok'] === '1' ? 1 : 0),
		];
		// Trench builder parts: which part, its size and material; one active item per part
		$data['trench_part'] = $data['part_size'] = $data['material'] = null;
		if ($cat === 'trench_parts') {
			$part = (string) ($p['trench_part'] ?? '');
			if (!isset(Trench::PARTS[$part])) {
				throw new \InvalidArgumentException('Pick which trench builder part this is.');
			}
			$size = preg_replace('/^w:/', '', (string) ($p['part_size'] ?? '')) ?? '';
			$data['trench_part'] = $part;
			if ($part === 'conduit') {
				if (!isset(Trench::CONDUITS[$size]) || str_starts_with((string) ($p['part_size'] ?? ''), 'w:')) {
					throw new \InvalidArgumentException('Pick the conduit size.');
				}
				$data['part_size'] = $size;
			} elseif ($part === 'wire') {
				$mat = (string) ($p['material'] ?? '');
				if (!in_array($size, Trench::SIZES, true) || !str_starts_with((string) ($p['part_size'] ?? ''), 'w:')) {
					throw new \InvalidArgumentException('Pick the wire size.');
				}
				if (!isset(Trench::MATERIALS[$mat])) {
					throw new \InvalidArgumentException('Pick copper or aluminum for the wire.');
				}
				$data['part_size'] = $size;
				$data['material'] = $mat;
			}
			$key = Trench::itemKey($data);
			foreach (Db::all("SELECT * FROM catalog_items WHERE category = 'trench_parts' AND is_active = 1 AND id <> ?", [$id ?? 0]) as $other) {
				if (!empty($p['is_active']) && Trench::itemKey($other) === $key) {
					throw new \InvalidArgumentException('"' . $other['name'] . '" is already the active catalog item for ' . Trench::partLabel((string) $key) . '. Retire it first, or edit that one.');
				}
			}
		}
		// Production modeling: the model's name in SAM's CEC library, degradation and warranty years
		$kind = CecLibrary::kindFor($cat);
		$fromSpec = $kind !== null && !empty($p['spec_mode']);
		$cec = $fromSpec ? '' : trim((string) ($p['cec_name'] ?? ''));
		$data['cec_name'] = $kind !== null && $cec !== '' ? $cec : null;
		$data['spec'] = null;
		if ($fromSpec) {
			if ($kind === 'inverters' && !$data['ac_kw']) {
				throw new \InvalidArgumentException('Spec sheet: fill in the AC kW (Specs and warranty) for this inverter.');
			}
			$data['spec'] = json_encode(CecLibrary::specFromInput($kind, (array) ($p['spec'] ?? []), $data['watts']));
		}
		if ($data['cec_name'] !== null && CecLibrary::available() && !CecLibrary::find((string) $kind, $data['cec_name'])) {
			throw new \InvalidArgumentException('"' . $cec . '" is not ' . ($kind === 'modules' ? 'a module' : 'an inverter') . ' in SAM\'s CEC library. Pick one from the list as you type.');
		}
		$units = $num('cec_units', 'CEC units per inverter');
		$data['cec_units'] = $cat === 'inverters' && $data['cec_name'] !== null && $units !== null && (int) $units > 1 ? (int) $units : null;
		$data['degradation_first_pct'] = $cat === 'modules' ? $num('degradation_first_pct', 'First-year degradation') : null;
		$data['degradation_annual_pct'] = $cat === 'modules' ? $num('degradation_annual_pct', 'Annual degradation') : null;
		foreach (['degradation_first_pct', 'degradation_annual_pct'] as $k) {
			if ($data[$k] !== null && $data[$k] > 10) {
				throw new \InvalidArgumentException('Degradation is a percent a year, e.g. 2 for the first year and 0.4 after.');
			}
		}
		$years = static function (string $k, string $label) use ($num): ?int {
			$y = $num($k, $label);
			if ($y !== null && ($y > 50 || floor($y) != $y)) {
				throw new \InvalidArgumentException($label . ' must be whole years, 50 or less.');
			}
			return $y === null ? null : (int) $y;
		};
		$data['product_warranty_years'] = in_array($cat, ['modules', 'inverters', 'mlpe', 'batteries', 'racking', 'monitoring'], true) ? $years('product_warranty_years', 'Product warranty') : null;
		$data['performance_warranty_years'] = $cat === 'modules' ? $years('performance_warranty_years', 'Performance warranty') : null;

		if ($data['domestic_content_pct'] !== null && $data['domestic_content_pct'] > 100) {
			throw new \InvalidArgumentException('Domestic content is a percent, 0 to 100.');
		}
		// Module size for the site layout; a spec-sheet item has it in its spec values already
		$spec = json_decode((string) $data['spec'], true) ?: [];
		foreach (['length_mm' => 'Length', 'width_mm' => 'Width'] as $k => $label) {
			$data[$k] = $cat === 'modules' ? ($num($k, $label) ?? (isset($spec[$k]) ? (float) $spec[$k] : null)) : null;
			if ($data[$k] !== null && ($data[$k] < 300 || $data[$k] > 4000)) {
				throw new \InvalidArgumentException($label . ' is in millimeters, e.g. 1722 by 1134.');
			}
		}
		if ($data['length_mm'] !== null && $data['width_mm'] !== null && $data['width_mm'] > $data['length_mm']) {
			[$data['length_mm'], $data['width_mm']] = [$data['width_mm'], $data['length_mm']];
		}
		if ($cat === 'modules' && !$data['watts']) {
			throw new \InvalidArgumentException('Modules need their watts (the build sheet sizes the system from them).');
		}
		// A changed price (or adders) is dated today; otherwise the date stays as it was
		$priceChanged = !$before;
		foreach (['price', 'shipping', 'setup_cost', 'min_total', 'ext_warranty_cost', 'siw_cost'] as $k) {
			if ($before && (string) ($before[$k] ?? '') !== (string) ($data[$k] ?? '') && !(is_numeric($before[$k]) && is_numeric($data[$k]) && abs((float) $before[$k] - (float) $data[$k]) < 0.00001)) {
				$priceChanged = true;
			}
		}
		if ($priceChanged) {
			$data['price_updated_at'] = date('Y-m-d');
			$data['price_updated_by'] = Auth::id();
		}
		return $data;
	}

	private function find(int $id): array
	{
		$item = Db::one('SELECT * FROM catalog_items WHERE id = ?', [$id]);
		if (!$item) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return $item;
	}
}
