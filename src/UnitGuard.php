<?php
declare(strict_types=1);

namespace App;

/**
 * Inputs whose unit invites a slip of a decimal place or more: a percent typed as a fraction (0.06
 * for 6%), dollars typed into a cents field (0.1308 for 13.08 cents), cents typed into a dollars
 * per watt field (22 for 0.22), MWh typed into a kWh field. Each field has the range a real value
 * falls in; a value outside it is rejected with the unit spelled out, instead of saving a number
 * that is 100 or 1,000 times off and quietly skews the price or the proposal.
 */
final class UnitGuard
{
	/**
	 * key => [lowest believable value, highest believable value (null: no limit), how to type it,
	 * whether 0 passes]. Blank always passes; the field's own range check still applies after this.
	 */
	public const FIELDS = [
		'ptc_cents'      => [1, null, 'is in cents per kWh, e.g. 13.08 (not 0.1308). Leave it blank if it isn\'t known', false],
		'tax_rate'       => [1, null, 'is a percent, e.g. 6 for 6% (not 0.06)', true],
		'markup'         => [1, null, 'is a percent, e.g. 33 for 33% (not 0.33)', true],
		'handoff_share'  => [1, null, 'is a percent, e.g. 10 for 10% (not 0.1)', true],
		'contingency'    => [1, null, 'is a percent, e.g. 10 for 10% (not 0.1)', true],
		'tier_rate'      => [0.1, null, 'is a percent, e.g. 5 for 5% or 0.75 for 0.75% (not 0.05)', true],
		'labor_per_watt' => [null, 10, 'is in dollars per watt, e.g. 0.22 (not 22 cents)', true],
		'other_loss'     => [1, null, 'is a percent, e.g. 14 for 14% (not 0.14)', true],
		'optimizer_loss' => [0.1, null, 'is a percent, e.g. 1.2 for 1.2% (not 0.012)', true],
		'degradation'    => [0.1, null, 'is a percent, e.g. 0.5 for 0.5% (not 0.005)', true],
		'domestic'       => [1, null, 'is a percent, e.g. 40 for 40% (not 0.4)', true],
		'voltage_drop'   => [0.5, null, 'is a percent, e.g. 3 for 3% (not 0.03)', true],
		'escalation'     => [0.1, null, 'is a percent a year, e.g. 3 for 3% (not 0.03)', true],
		'srec_price'     => [1, null, 'is in dollars per MWh, e.g. 20 (not 0.02 per kWh)', true],
		'srec_decline'   => [0.1, null, 'is a percent a year, e.g. 3 for 3% (not 0.03)', true],
		'discount'       => [0.5, null, 'is a percent a year, e.g. 7 for 7% (not 0.07)', true],
		'dep_fed_rate'   => [1, null, 'is a percent, e.g. 22 for 22% (not 0.22)', true],
		'dep_state_rate' => [0.5, null, 'is a percent, e.g. 3.07 for 3.07% (not 0.0307)', true],
		'finance_rate'   => [1, null, 'is a yearly percent, e.g. 6.99 for 6.99% (not 0.0699)', true],
		'incentive_pct'  => [1, null, 'is a percent of the price, e.g. 30 for 30% (not 0.3)', true],
		'incentive_amt'  => [1, null, 'is in dollars. For a percent of the price, pick % and type 30 for 30%', true],
		'annual_kwh'     => [100, null, 'is in kWh a year, e.g. 14,200 (not 14.2 MWh)', true],
	];

	/** The message for a value that looks like a unit slip, else null. */
	public static function problem(string $key, ?float $v, string $label): ?string
	{
		if ($v === null || !isset(self::FIELDS[$key])) {
			return null;
		}
		[$low, $high, $how, $zeroOk] = self::FIELDS[$key];
		if ($v == 0 && $zeroOk) {
			return null;
		}
		if (($low !== null && $v < $low) || ($high !== null && $v > $high)) {
			return $label . ' ' . $how . '.';
		}
		return null;
	}

	/** Throws the message for a value that looks like a unit slip. */
	public static function check(string $key, ?float $v, string $label): void
	{
		if (($msg = self::problem($key, $v, $label)) !== null) {
			throw new \InvalidArgumentException($msg);
		}
	}
}
