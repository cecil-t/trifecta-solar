<?php
use App\Service;

require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
// Each row carries its warranty trips/hours so the page can price warranty work live.
$wAttrs = static fn (array $s) => ' data-wtrips="' . $s['w_trips'] . '" data-whours="' . $s['w_hours'] . '"';
$row = static function (string $label, array $s, bool $isWarranty = false) use ($money2, $wAttrs): string {
	$amount = $isWarranty
		? '<span class="est" data-est></span>'
		: ($s['billed'] ? $money2($s['amount']) : '');
	return '<tr' . $wAttrs($s) . '><td>' . $label . '</td><td class="num">' . $s['tickets'] . '</td><td class="num">' . $s['trips'] . '</td><td class="num">'
		. e(Service::hours($s['hours'])) . '</td><td class="num">' . $amount . '</td></tr>';
};
$monthRow = static function (string $label, array $s) use ($money2, $wAttrs): string {
	return '<tr' . $wAttrs($s) . '><td>' . $label . '</td><td class="num">' . $s['tickets'] . '</td><td class="num">' . $s['trips'] . '</td><td class="num">'
		. e(Service::hours($s['hours'])) . '</td><td class="num">' . ($s['billed'] ? $money2($s['amount']) : '') . '</td><td class="num"><span class="est" data-est></span></td></tr>';
};
?>
<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<div><span class="m-num"><?= $totals['tickets'] ?></span><span class="m-label">Tickets</span></div>
		<div><span class="m-num"><?= $totals['trips'] ?></span><span class="m-label">Trips</span></div>
		<div><span class="m-num"><?= e(Service::hours($totals['hours'])) ?: '0' ?></span><span class="m-label">Man-hours</span></div>
		<div><span class="m-num"><?= $money($totals['amount']) ?></span><span class="m-label">Amount billed</span></div>
		<div id="w-total"<?= $wAttrs($totals) ?>><span class="m-num est-num" data-est data-round></span><span class="m-label">Warranty cost (est.)</span></div>
	</div>

	<div class="rate-calc">
		<span class="rate-calc-label">Price warranty work at</span>
		<label>Trip charge <span class="money-input">$<input type="number" id="rate-trip" value="225" min="0" step="5" inputmode="decimal"></span></label>
		<label>Hourly rate <span class="money-input">$<input type="number" id="rate-hour" value="95" min="0" step="5" inputmode="decimal"></span></label>
		<span class="muted small no-print">Not saved; reloading resets to $225 and $95.</span>
		<span class="rate-note">The first hour is not included in the trip charge: every man-hour is priced at the hourly rate.</span>
		<span class="print-only small">Warranty priced at $<span data-show="trip">225</span> per trip + $<span data-show="hour">95</span> per man-hour (first hour not included in the trip charge)</span>
	</div>
	<p class="hint report-hint">Tickets opened in the date range. Amount billed is what was entered on each ticket (customer or SolarInsure). Warranty cost is an estimate: trips &times; trip charge + man-hours &times; hourly rate.</p>

	<h2 class="sub report-sub">By coverage</h2>
	<table class="table">
		<thead><tr><th>Coverage</th><th class="num">Tickets</th><th class="num">Trips</th><th class="num">Man-hours</th><th class="num">Amount billed</th></tr></thead>
		<tbody>
		<?php foreach ($byCoverage as $c => $s): ?><?= $row($c === '' ? '<span class="muted">Not set</span>' : e(Service::COVERAGE[$c]) . ($c === 'warranty' ? ' <span class="muted small">(est. cost)</span>' : ''), $s, $c === 'warranty') ?><?php endforeach; ?>
		</tbody>
		<tfoot><tr><td>Total billed</td><td class="num"><?= $totals['tickets'] ?></td><td class="num"><?= $totals['trips'] ?></td><td class="num"><?= e(Service::hours($totals['hours'])) ?></td><td class="num"><?= $money2($totals['amount']) ?></td></tr></tfoot>
	</table>

	<h2 class="sub report-sub">By month</h2>
	<table class="table">
		<thead><tr><th>Month</th><th class="num">Tickets</th><th class="num">Trips</th><th class="num">Man-hours</th><th class="num">Amount billed</th><th class="num">Warranty cost (est.)</th></tr></thead>
		<tbody>
		<?php if (!$byMonth): ?><tr><td colspan="6" class="empty">No service tickets in this range.</td></tr><?php endif; ?>
		<?php foreach ($byMonth as $ym => $s): if ($f['preset'] === 'all' && !$s['tickets']) { continue; } ?><?= $monthRow(str_replace(' ', '&nbsp;', e($monthLabel($ym))), $s) ?><?php endforeach; ?>
		</tbody>
	</table>
</section>

<script>
// Warranty cost estimate, priced live from the two rate boxes.
(function () {
	const trip = document.getElementById('rate-trip');
	const hour = document.getElementById('rate-hour');
	const fmt = (n, round) => '$' + n.toLocaleString('en-US', { minimumFractionDigits: round ? 0 : 2, maximumFractionDigits: round ? 0 : 2 });
	function calc() {
		const t = parseFloat(trip.value) || 0;
		const h = parseFloat(hour.value) || 0;
		document.querySelectorAll('[data-show="trip"]').forEach((el) => { el.textContent = t; });
		document.querySelectorAll('[data-show="hour"]').forEach((el) => { el.textContent = h; });
		document.querySelectorAll('[data-wtrips]').forEach((row) => {
			const cost = (+row.dataset.wtrips) * t + (+row.dataset.whours) * h;
			row.querySelectorAll('[data-est]').forEach((el) => {
				el.textContent = cost || el.hasAttribute('data-round') ? fmt(cost, el.hasAttribute('data-round')) : '';
			});
		});
	}
	trip.addEventListener('input', calc);
	hour.addEventListener('input', calc);
	calc();
})();
</script>
