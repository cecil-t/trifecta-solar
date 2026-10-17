<?php
/**
 * One line about a picked rate's history (UtilityRates::rateTrend, saved on the opportunity).
 *
 * @var array $t
 */
$line = static fn (array $c, int $y): string => $c['pct'] === null ? $y . ' years: not enough OpenEI data (' . $c['why'] . ')'
	: $y . ' years: ' . sprintf('%+.1f%%', $c['pct']) . ' a year';
$unapproved = !empty($t['y3']['unapproved']) || !empty($t['y5']['unapproved']);
?>
History for this rate: <?= e($line($t['y3'], 3)) ?>; <?= e($line($t['y5'], 5)) ?><?= $unapproved ? ' (includes versions OpenEI hasn\'t approved yet)' : '' ?>.
<?php if ($t['generation'] !== null): ?>Generation part now about <?= e(number_format((float) $t['generation'], 2)) ?> cents per kWh (used for the May net metering true-up).<?php endif; ?>
