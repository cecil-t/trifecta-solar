<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Presales;
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
		View::render('presales/catalog', ['title' => 'Catalog', 'groups' => $ordered, 'q' => $q, 'cat' => $cat, 'show' => $show,
			'staleCount' => $staleCount, 'canEdit' => Presales::canEdit()]);
	}

	public function create(): void
	{
		Presales::requireEdit();
		$item = $_SESSION['old_catalog'] ?? ['category' => (string) ($_GET['category'] ?? 'modules'), 'qty_rule' => 'manual', 'tax_class' => 'major', 'is_active' => 1,
			'price_updated_at' => date('Y-m-d')];
		unset($_SESSION['old_catalog']);
		View::render('presales/catalog_form', ['title' => 'New catalog item', 'item' => $item, 'id' => null, 'activity' => []]);
	}

	public function edit(int $id): void
	{
		$saved = $this->find($id);
		$item = $_SESSION['old_catalog'] ?? $saved;
		unset($_SESSION['old_catalog']);
		$used = (int) Db::value('SELECT COUNT(DISTINCT design_id) FROM presales_design_lines WHERE catalog_item_id = ?', [$id]);
		View::render('presales/catalog_form', ['title' => $saved['name'], 'item' => $item, 'id' => $id, 'used' => $used,
			'activity' => Activity::feed('catalog_item', $id), 'canEdit' => Presales::canEdit()]);
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
			redirect('/presales/catalog/new');
		}
		flash('success', '"' . $data['name'] . '" added.');
		redirect('/presales/catalog?category=' . rawurlencode($data['category']) . '#item-' . $id);
	}

	public function update(int $id): void
	{
		Presales::requireEdit();
		$before = $this->find($id);
		try {
			$data = $this->input($id, $before);
			$data['updated_at'] = now_utc();
			Db::update('catalog_items', $id, $data);
			Activity::changes('catalog_item', $id, $before, $data, self::TRACKED);
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_catalog'] = $_POST + ['id' => $id];
			flash('error', $e->getMessage());
			redirect('/presales/catalog/' . $id);
		}
		flash('success', 'Saved "' . $data['name'] . '". Build sheets already using it keep their price until someone updates them.');
		redirect('/presales/catalog?category=' . rawurlencode($data['category']) . '#item-' . $id);
	}

	/** The price was checked and is still right: date it today without changing it. */
	public function confirm(int $id): void
	{
		Presales::requireEdit();
		$item = $this->find($id);
		$source = trim((string) ($_POST['price_source'] ?? '')) ?: $item['price_source'];
		Db::update('catalog_items', $id, ['price_updated_at' => date('Y-m-d'), 'price_updated_by' => Auth::id(), 'price_source' => $source, 'updated_at' => now_utc()]);
		Activity::event('catalog_item', $id, 'Price confirmed at ' . Presales::unit((float) $item['price']));
		flash('success', 'Price for "' . $item['name'] . '" confirmed as of today.');
		$back = local_path((string) ($_POST['back'] ?? ''), '/presales/catalog');
		redirect($back . '#item-' . $id);
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
			'feoc_ok' => ($p['feoc_ok'] ?? '') === '' ? null : ((string) $p['feoc_ok'] === '1' ? 1 : 0),
		];
		if ($data['domestic_content_pct'] !== null && $data['domestic_content_pct'] > 100) {
			throw new \InvalidArgumentException('Domestic content is a percent, 0 to 100.');
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
