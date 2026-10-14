<?php
declare(strict_types=1);

namespace App;

/**
 * The Opportunities report: Sales pipeline by salesperson.
 *
 * An opportunity's value is its sold build sheet once sold, otherwise the average of its build
 * sheets (only one will close, so adding them up would overstate the pipeline). Opportunities are
 * dated by when they were created. "Going cold" is every open opportunity whose latest change
 * (an edit, a build sheet save, a status change or a log note) is 30 or more days old. Overrides
 * are every contingency or line price override, with its reason, on any of the opportunity's build sheets.
 */
final class OpportunityReport
{
	public const COLD_DAYS = 30;

	/**
	 * @param string $class 'residential' | 'commercial' | '' (both)
	 */
	public static function build(string $from, string $to, int $salesId, string $class): array
	{
		[$vis, $vp] = Presales::visibleWhere();
		$opps = Db::all(
			'SELECT o.*, u.name AS salesperson_name,
				(SELECT MAX(a.created_at) FROM activity_log a WHERE a.entity_type = \'presales\' AND a.entity_id = o.id) AS last_log
			 FROM presales_opportunities o LEFT JOIN users u ON u.id = o.salesperson_id WHERE ' . $vis,
			$vp
		);
		$rows = [];
		$total = self::blank('Total');
		$cold = [];
		$lost = [];
		$overrides = [];
		foreach ($opps as $o) {
			if ($class !== '' && Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']) !== $class) {
				continue;
			}
			if ($salesId && (int) $o['salesperson_id'] !== $salesId) {
				continue;
			}
			$v = self::value($o);
			$last = max((string) $o['updated_at'], (string) ($o['last_log'] ?? ''));
			if ($o['status'] === 'open') {
				$days = (int) floor((time() - strtotime($last)) / 86400);
				if ($days >= self::COLD_DAYS) {
					$cold[] = ['o' => $o, 'v' => $v, 'last' => $last, 'days' => $days];
				}
			}
			$created = substr(fmt_dt((string) $o['created_at'], 'Y-m-d'), 0, 10);
			if ($created < $from || $created > $to) {
				continue;
			}
			$key = (int) $o['salesperson_id'];
			$rows[$key] ??= self::blank($o['salesperson_name'] ?? 'No salesperson');
			foreach ([&$rows[$key], &$total] as &$r) {
				self::add($r, $o, $v);
			}
			unset($r);
			foreach ($v['override_list'] as $ov) {
				$overrides[] = $ov + ['o' => $o];
			}
			if ($o['status'] === 'lost') {
				$reason = trim((string) $o['lost_reason']);
				$k = $reason === '' ? '' : mb_strtolower($reason);
				$lost[$k] ??= ['reason' => $reason === '' ? 'No reason given' : $reason, 'count' => 0, 'who' => []];
				$lost[$k]['count']++;
				$lost[$k]['who'][$o['salesperson_name'] ?? 'No salesperson'] = true;
			}
		}
		$rows = array_map([self::class, 'finish'], $rows);
		uasort($rows, static fn ($a, $b) => strcmp($a['name'], $b['name']));
		usort($cold, static fn ($a, $b) => $b['days'] <=> $a['days']);
		uasort($lost, static fn ($a, $b) => $b['count'] <=> $a['count']);
		return ['rows' => $rows, 'total' => self::finish($total), 'cold' => $cold, 'lost' => array_values($lost), 'overrides' => $overrides];
	}

	/** Price, kW, $/W, margin, markup and overrides for one opportunity (sold sheet, else the average). */
	public static function value(array $o): array
	{
		$designs = Db::all('SELECT * FROM presales_designs WHERE opportunity_id = ?', [(int) $o['id']]);
		// Overrides count on every build sheet, sold or not: each one was a price someone quoted
		$list = [];
		foreach ($designs as $d) {
			if ($d['contingency_override'] !== null && trim((string) $d['contingency_reason']) !== '') {
				$list[] = ['design' => $d['name'], 'design_id' => (int) $d['id'], 'what' => 'Contingency', 'was' => 'Rules',
					'now' => Presales::pct((float) $d['contingency_override'] / 100), 'reason' => (string) $d['contingency_reason']];
			}
			foreach (Db::all("SELECT * FROM presales_design_lines WHERE design_id = ? AND price_override IS NOT NULL AND TRIM(COALESCE(override_reason, '')) <> '' ORDER BY sort_order", [(int) $d['id']]) as $l) {
				$list[] = ['design' => $d['name'], 'design_id' => (int) $d['id'], 'what' => $l['name'],
					'was' => Presales::price((float) $l['price'] + (float) $l['shipping'], $l['qty_rule']), 'now' => Presales::price((float) $l['price_override'], $l['qty_rule']),
					'reason' => (string) $l['override_reason']];
			}
		}
		if ($o['status'] === 'sold' && $o['sold_design_id']) {
			$designs = array_values(array_filter($designs, static fn ($d) => (int) $d['id'] === (int) $o['sold_design_id'])) ?: $designs;
		}
		$n = 0;
		$sum = ['price' => 0.0, 'kw' => 0.0, 'margin' => 0.0, 'markup' => 0.0];
		$marginN = 0;
		foreach ($designs as $d) {
			if ((float) $d['price_total'] <= 0) {
				continue;
			}
			$calc = Designs::calc($d, Designs::lines((int) $d['id']), Designs::arrays((int) $d['id']));
			$n++;
			$sum['price'] += $calc['price'];
			$sum['kw'] += $calc['dc_kw'];
			$sum['markup'] += (float) $d['markup'];
			if ($calc['gross_margin_pct'] !== null) {
				$sum['margin'] += $calc['gross_margin_pct'];
				$marginN++;
			}
		}
		return [
			'priced' => $n > 0,
			'price' => $n ? $sum['price'] / $n : 0.0,
			'kw' => $n ? $sum['kw'] / $n : 0.0,
			'margin' => $marginN ? $sum['margin'] / $marginN : null,
			'markup' => $n ? $sum['markup'] / $n : null,
			'overrides' => count($list),
			'override_list' => $list,
		];
	}

	private static function blank(string $name): array
	{
		return ['name' => $name, 'count' => 0, 'open' => 0, 'sold' => 0, 'lost' => 0, 'open_value' => 0.0, 'sold_value' => 0.0,
			'priced' => 0, 'price_sum' => 0.0, 'kw_sum' => 0.0, 'margin_sum' => 0.0, 'margin_n' => 0, 'markup_sum' => 0.0,
			'markup_n' => 0, 'overrides' => 0, 'close_days' => 0, 'close_n' => 0];
	}

	private static function add(array &$r, array $o, array $v): void
	{
		$r['count']++;
		$r[$o['status']]++;
		$r['overrides'] += $v['overrides'];
		if ($v['priced']) {
			$r['priced']++;
			$r['price_sum'] += $v['price'];
			$r['kw_sum'] += $v['kw'];
			if ($o['status'] === 'open') {
				$r['open_value'] += $v['price'];
			}
			if ($o['status'] === 'sold') {
				$r['sold_value'] += $v['price'];
			}
		}
		if ($v['margin'] !== null) {
			$r['margin_sum'] += $v['margin'];
			$r['margin_n']++;
		}
		if ($v['markup'] !== null) {
			$r['markup_sum'] += $v['markup'];
			$r['markup_n']++;
		}
		if ($o['status'] === 'sold' && $o['status_at']) {
			$r['close_days'] += max(0, (int) round((strtotime((string) $o['status_at']) - strtotime((string) $o['created_at'])) / 86400));
			$r['close_n']++;
		}
	}

	private static function finish(array $r): array
	{
		$decided = $r['sold'] + $r['lost'];
		return $r + [
			'win_rate' => $decided ? $r['sold'] / $decided : null,
			'avg_price' => $r['priced'] ? $r['price_sum'] / $r['priced'] : null,
			'avg_kw' => $r['priced'] ? $r['kw_sum'] / $r['priced'] : null,
			'avg_ppw' => $r['kw_sum'] > 0 ? $r['price_sum'] / ($r['kw_sum'] * 1000) : null,
			'avg_margin' => $r['margin_n'] ? $r['margin_sum'] / $r['margin_n'] : null,
			'avg_markup' => $r['markup_n'] ? $r['markup_sum'] / $r['markup_n'] : null,
			'avg_close' => $r['close_n'] ? $r['close_days'] / $r['close_n'] : null,
		];
	}
}
