<?php
declare(strict_types=1);

namespace App;

/**
 * Commission plan math. A plan is tax-bracket style tiers: each rate applies only to the part of
 * the amount inside its bracket, so there is no cliff at a threshold.
 *
 * Tiers: [['upto' => 50000.0, 'rate' => 0.03], ['upto' => null, 'rate' => 0.02]] (rates as fractions,
 * the last tier open ended). A plan: ['commission' => tiers, 'self_gen' => tiers,
 * 'referral_bonus' => dollars, 'handoff_share' => fraction].
 */
final class Commission
{
	/** Tiers cleaned up: sorted by upper limit, open-ended tier last, only one open tier. */
	public static function tiers(mixed $tiers): array
	{
		$out = [];
		foreach ((array) $tiers as $t) {
			$rate = (float) ($t['rate'] ?? 0);
			$upto = $t['upto'] ?? null;
			$upto = $upto === null || $upto === '' ? null : (float) $upto;
			if ($rate < 0) {
				continue;
			}
			$out[] = ['upto' => $upto, 'rate' => $rate];
		}
		usort($out, static fn ($a, $b) => ($a['upto'] ?? INF) <=> ($b['upto'] ?? INF));
		$open = array_values(array_filter($out, static fn ($t) => $t['upto'] === null));
		$out = array_values(array_filter($out, static fn ($t) => $t['upto'] !== null));
		if ($open) {
			$out[] = $open[0];
		}
		return $out;
	}

	/** The amount a tier table pays on $amount (each rate on its own bracket). */
	public static function tiered(array $tiers, float $amount): float
	{
		$total = 0.0;
		$lower = 0.0;
		foreach (self::tiers($tiers) as $t) {
			if ($amount <= $lower) {
				break;
			}
			$top = $t['upto'] === null ? $amount : min($amount, $t['upto']);
			if ($top > $lower) {
				$total += ($top - $lower) * $t['rate'];
			}
			if ($t['upto'] === null) {
				break;
			}
			$lower = $t['upto'];
		}
		return $total;
	}

	/**
	 * The price P that covers its own commission: P = $base + tiered(P). Commission is a share of
	 * the price, so it can't be added as a markup on cost; each bracket is solved exactly.
	 */
	public static function grossUp(array $tiers, float $base): float
	{
		$tiers = self::tiers($tiers);
		if ($base <= 0 || !$tiers) {
			return max(0.0, $base);
		}
		$lower = 0.0;
		foreach ($tiers as $t) {
			$r = $t['rate'];
			if ($r >= 1) {
				return $base;
			}
			$p = ($base + self::tiered($tiers, $lower) - $r * $lower) / (1 - $r);
			if ($p >= $lower - 0.000001 && ($t['upto'] === null || $p <= $t['upto'] + 0.000001)) {
				return $p;
			}
			if ($t['upto'] === null) {
				break;
			}
			$lower = $t['upto'];
		}
		// Rates above the last limit are missing: no commission beyond it
		return $base + self::tiered($tiers, $lower);
	}

	/** "3% up to $50,000, then 2% over $50,000" for hints and summaries. */
	public static function describe(array $tiers): string
	{
		$tiers = self::tiers($tiers);
		if (!$tiers) {
			return 'none set';
		}
		$parts = [];
		$lower = 0.0;
		foreach ($tiers as $i => $t) {
			$rate = rtrim(rtrim(number_format($t['rate'] * 100, 2), '0'), '.') . '%';
			if ($t['upto'] === null) {
				$parts[] = ($i ? 'then ' : '') . $rate . ($i ? ' over ' . Presales::money($lower) : '');
			} else {
				$parts[] = $rate . ($i ? ' from ' . Presales::money($lower) : '') . ' up to ' . Presales::money($t['upto']);
			}
			$lower = (float) $t['upto'];
		}
		return implode(', ', $parts);
	}

	/** The plan on the Rates page now. */
	public static function currentPlan(): array
	{
		return [
			'commission' => self::tiers(json_decode(Presales::setting('commission_tiers'), true) ?: []),
			'self_gen' => self::tiers(json_decode(Presales::setting('self_gen_tiers'), true) ?: []),
			'referral_bonus' => Presales::num('referral_bonus'),
			'handoff_share' => Presales::num('handoff_share'),
		];
	}

	/** A plan read from JSON (a build sheet's or a commission record's), or null for none. */
	public static function plan(?string $json): ?array
	{
		if ($json === null || $json === '') {
			return null;
		}
		$p = json_decode($json, true);
		if (!is_array($p)) {
			return null;
		}
		return [
			'commission' => self::tiers($p['commission'] ?? []),
			'self_gen' => self::tiers($p['self_gen'] ?? []),
			'referral_bonus' => (float) ($p['referral_bonus'] ?? 0),
			'handoff_share' => (float) ($p['handoff_share'] ?? 0),
		];
	}

	/**
	 * What a sold job pays, from its plan: commission on the commissionable amount, the
	 * self-generated lead bonus (net-new customers only), the referral bonus, and the split.
	 */
	public static function amounts(array $plan, float $amount, ?string $leadSource, bool $selfGenAllowed = true): array
	{
		$commission = self::tiered($plan['commission'], $amount);
		$selfGen = $leadSource === 'self_gen' && $selfGenAllowed ? self::tiered($plan['self_gen'], $amount) : 0.0;
		return [
			'commission' => round($commission, 2),
			'self_gen' => round($selfGen, 2),
			'referral_bonus' => $leadSource === 'referral' ? (float) $plan['referral_bonus'] : 0.0,
		];
	}
}
