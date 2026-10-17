<?php
/**
 * One line about a picked rate's history (UtilityRates::rateTrend, saved on the opportunity).
 * Histories saved before the 10 year figure existed have only 3 and 5.
 *
 * @var array $t
 */
$line = static fn (array $c, int $y): string => $c['pct'] === null ? $y . ' years: not enough OpenEI data (' . $c['why'] . ')'
	: $y . ' years: ' . sprintf('%+.1f%%', $c['pct']) . ' a year';
$parts = [];
$unapproved = false;
foreach ([3, 5, 10] as $y) {
	if (isset($t['y' . $y])) {
		$parts[] = $line($t['y' . $y], $y);
		$unapproved = $unapproved || !empty($t['y' . $y]['unapproved']);
	}
}
?>
History for this rate: <?= e(implode('; ', $parts)) ?><?= $unapproved ? ' (includes versions OpenEI hasn\'t approved yet)' : '' ?>.
<?php if ($t['generation'] !== null): ?>Generation part now about <?= e(number_format((float) $t['generation'], 2)) ?> cents per kWh (used for the May net metering true-up).<?php endif; ?>
