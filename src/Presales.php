<?php
declare(strict_types=1);

namespace App;

/** Pre-Sales: catalog categories, rates (settings), and who may edit them. */
final class Presales
{
	/** Catalog categories in build sheet order: label, and the unit a manual quantity counts. */
	public const CATEGORIES = [
		'modules'         => ['Modules', 'ea'],
		'inverters'       => ['Inverters', 'ea'],
		'mlpe'            => ['Optimizers, micros and rapid shutdown', 'ea'],
		'batteries'       => ['Batteries', 'ea'],
		'monitoring'      => ['Monitoring and internet', 'ea'],
		'racking'         => ['Racking', 'ea'],
		'bos'             => ['Balance of System', 'ea'],
		'electrical'      => ['Electrical and service upgrades', 'ea'],
		'sitework'        => ['Trenching and boring', 'ft'],
		'structural'      => ['Structural analysis', 'ea'],
		'foundation'      => ['Foundations', 'ea'],
		'equipment'       => ['Equipment rental', 'ea'],
		'permits'         => ['Permits', 'ea'],
		'interconnection' => ['Interconnection', 'ea'],
		'misc'            => ['Other', 'ea'],
		'warranty'        => ['Warranty programs', 'ea'],
	];

	public const TAX_CLASSES = [
		'major'    => 'Major equipment (taxed unless tax exempt)',
		'bos'      => 'BOS (always taxed)',
		'included' => 'Tax included (third party)',
		'none'     => 'No tax (fees, services)',
	];
	public const TAX_SHORT = ['major' => 'Major', 'bos' => 'BOS', 'included' => 'Tax incl.', 'none' => 'No tax'];

	public const QTY_RULES = [
		'manual'       => 'Entered on the build sheet',
		'dc_watts'     => 'Per DC watt (automatic)',
		'modules'      => 'One per module (automatic)',
		'module_pairs' => 'One per two modules (automatic)',
	];

	public const QTY_SHORT = ['manual' => 'Each', 'dc_watts' => 'Per DC watt', 'modules' => 'Per module', 'module_pairs' => 'Per 2 modules'];

	public const STATUSES = ['open' => 'Open', 'sold' => 'Sold', 'lost' => 'Lost'];

	public const MOUNTS = ['roof' => 'Roof', 'ground' => 'Ground', 'carport' => 'Carport', 'pole' => 'Pole', 'tracker' => 'Tracker'];

	public const TRIGGERS = [
		'always'           => 'Always on',
		'ground_work'      => 'Automatic: trenching, boring, foundations or a ground, pole or tracker array',
		'pole_tracker'     => 'Automatic: a pole or tracker array, or tracker install type',
		'difficult_access' => 'Automatic: an array steeper than the steep threshold, or equipment rental (crane, telehandler); can also be checked by hand',
		'manual'           => 'Checked by hand on the build sheet',
	];

	/** Settings with their defaults and labels (values come from the import and the Rates page). */
	public const SETTINGS = [
		'tax_rate'               => ['0.06', 'Sales tax rate'],
		'tax_note'               => ['PA Tax', 'Tax note'],
		'markup_default'         => ['0', 'Default markup'],
		'labor_default'          => ['', 'Default labor price ($ per watt)'],
		'labor_rate'             => ['0', 'Labor rate ($ per man-hour)'],
		'lodging_rate'           => ['0', 'Lodging ($ per person per night)'],
		'per_diem_rate'          => ['0', 'Per diem ($ per person per day)'],
		'labor_guideline'        => ['', 'Labor guideline (shown beside the labor price)'],
		'site_visit'             => ['0', 'Site visit (paid to the salesperson)'],
		'contingency_cap'        => ['15', 'Contingency cap (%)'],
		'contingency_floor'      => ['0', 'Contingency minimum ($)'],
		'steep_deg'              => ['30', 'Steep array threshold (degrees)'],
		'stale_days'             => ['90', 'Catalog price goes stale after (days)'],
		'round_to'               => ['100', 'Round the final price up to the next ($)'],
		'trifecta_warranty_text' => ['', 'Trifecta labor and workmanship warranty wording'],
		'solarinsure_text'       => ['', 'SolarInsure wording'],
	];

	private static ?array $settings = null;

	public static function setting(string $key): string
	{
		if (self::$settings === null) {
			self::$settings = [];
			foreach (Db::all('SELECT key, value FROM presales_settings') as $r) {
				self::$settings[$r['key']] = (string) $r['value'];
			}
		}
		$v = self::$settings[$key] ?? null;
		return $v === null || $v === '' ? (self::SETTINGS[$key][0] ?? '') : $v;
	}

	public static function num(string $key): float
	{
		return (float) self::setting($key);
	}

	public static function forgetSettings(): void
	{
		self::$settings = null;
	}

	/** Admins and salespeople edit the catalog and the rates. */
	public static function canEdit(): bool
	{
		$u = Auth::user();
		return Auth::isAdmin() || !empty($u['is_sales']);
	}

	public static function requireEdit(): void
	{
		if (!self::canEdit()) {
			View::render('errors/403', ['title' => 'Not allowed'], 'layout', 403);
			exit;
		}
	}

	public static function categoryLabel(string $cat): string
	{
		return $cat === 'extra' ? 'Extras without markup' : (self::CATEGORIES[$cat][0] ?? ucfirst($cat));
	}

	/** Active catalog items grouped by category, in category order. */
	public static function catalogByCategory(bool $activeOnly = true): array
	{
		$rows = Db::all('SELECT * FROM catalog_items' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
		$out = array_fill_keys(array_keys(self::CATEGORIES), []);
		foreach ($rows as $r) {
			$out[$r['category']][] = $r;
		}
		return $out;
	}

	/** A catalog price older than the stale setting (or never dated) needs checking. */
	public static function isStale(?string $date): bool
	{
		if (!$date) {
			return true;
		}
		$days = max(1, (int) self::num('stale_days'));
		return strtotime($date . ' 00:00:00') < strtotime('-' . $days . ' days');
	}

	public static function commissionRates(): array
	{
		return Db::all('SELECT * FROM presales_commission_rates ORDER BY rate DESC');
	}

	public static function paymentTemplates(): array
	{
		return Db::all('SELECT * FROM presales_payment_templates ORDER BY sort_order, id');
	}

	public static function contingencyRules(bool $activeOnly = true): array
	{
		return Db::all('SELECT * FROM presales_contingency_rules' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
	}

	public static function depositTiers(): array
	{
		return Db::all('SELECT * FROM presales_deposit_tiers ORDER BY class DESC, min_kw, sort_order');
	}

	/**
	 * Residential or Non-Residential (stored as 'commercial'): commercial, agricultural, municipal and
	 * non-profit jobs are all Non-Residential. Decides deposit tiers and which BOS, structural, permit
	 * and interconnection items a build sheet offers.
	 */
	public static function depositClass(?string $customerType, bool $agricultural = false): string
	{
		return ($customerType ?? 'residential') === 'residential' && !$agricultural ? 'residential' : 'commercial';
	}

	public const CLASS_LABELS = ['residential' => 'Residential', 'commercial' => 'Non-Residential'];
	public const SEGMENT_LABELS = ['' => 'All jobs', 'residential' => 'Residential only', 'commercial' => 'Non-Residential only'];
	public const MOUNT_FILTERS = ['roof' => 'Rooftop', 'ground' => 'Ground mount', 'tracker' => 'Tracker'];

	/** Deposit amounts allowed for a class and DC size; the first is the default. */
	public static function depositOptions(string $class, float $dcKw): array
	{
		$out = [];
		foreach (self::depositTiers() as $t) {
			if ($t['class'] !== $class) {
				continue;
			}
			if ($dcKw >= (float) $t['min_kw'] && ($t['max_kw'] === null || $dcKw < (float) $t['max_kw'])) {
				$out[] = (float) $t['amount'];
			}
		}
		return array_values(array_unique($out));
	}

	/** Money with cents dropped: $1,234. */
	public static function money(?float $v): string
	{
		if ($v === null) {
			return '';
		}
		return ($v < 0 ? '-$' : '$') . number_format(abs($v), 0);
	}

	/** A unit price: per-watt and small prices keep 4 decimals, others 2. */
	public static function unit(?float $v): string
	{
		if ($v === null) {
			return '';
		}
		$dec = abs($v) < 10 && round($v, 2) != round($v, 4) ? 4 : 2;
		return '$' . number_format($v, $dec);
	}

	/** A $/W figure always at 4 decimals ($0.1851). */
	public static function perWatt(?float $v): string
	{
		return $v === null ? '' : '$' . number_format($v, 4);
	}

	public static function pct(?float $v, int $dec = 1): string
	{
		return $v === null ? '' : number_format($v * 100, $dec) . '%';
	}

	/** A plain number for an input field: no thousands separators, trailing zeros dropped. */
	public static function field(?float $v, int $dec = 4): string
	{
		if ($v === null) {
			return '';
		}
		$s = number_format($v, $dec, '.', '');
		return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
	}
}
