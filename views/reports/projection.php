<?php
require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
?>
<div class="card card-flush report-card-body">
	<div class="rate-calc">
		<label>Estimated growth <span class="money-input"><input type="number" id="growth" value="15" step="1" inputmode="decimal">%</span></label>
		<span class="muted small no-print">Applied to each window's pace. Not saved; reloading resets to 15%.</span>
		<span class="print-only small">Includes <span data-show="growth">15</span>% estimated growth over current pace.</span>
	</div>
	<p class="hint report-hint">Each window's pace, scaled to 12 months, plus the growth rate. The small figure under each is the flat pace with no growth. Windows end on the as-of date.</p>
	<table class="table" id="proj-table">
		<thead><tr><th>Based on</th><th class="num">Actual in window</th><th class="num">Projects / yr</th><th class="num">Contract value / yr</th><th class="num">kW DC / yr</th></tr></thead>
		<tbody>
		<?php foreach ($projections as $label => $pr): $n = $pr['next12']; ?>
			<tr>
				<td><?= e($label) ?><div class="muted small"><?= e(fmt_date($pr['from'])) ?> to <?= e(fmt_date($pr['to'])) ?></div>
					<?php if ($pr['partial']): ?><span class="chip chip-orange" title="The tracker has no signed projects before <?= e(fmt_date($earliest)) ?>, so this window is missing earlier sales and reads low">Incomplete history</span><?php endif; ?>
				</td>
				<td class="num small"><?= $pr['actual']['count'] ?> &middot; <?= $money($pr['actual']['price']) ?> &middot; <?= $kwf($pr['actual']['kw']) ?> kW</td>
				<td class="num" data-base="<?= $n['count'] ?>" data-kind="count"><strong data-v></strong><div class="muted small">flat <?= number_format($n['count'], 1) ?></div></td>
				<td class="num" data-base="<?= $n['price'] ?>" data-kind="money"><strong data-v></strong><div class="muted small">flat <?= $money($n['price']) ?></div></td>
				<td class="num" data-base="<?= $n['kw'] ?>" data-kind="kw"><strong data-v></strong><div class="muted small">flat <?= $kwf($n['kw']) ?></div></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="hint report-hint">The tracker holds the 2026 tab plus a few older completed jobs, so windows reaching back into 2025 are missing sales that finished before 2026.</p>
</div>

<script>
// Growth-adjusted projection, recalculated as the percentage changes.
(function () {
	const g = document.getElementById('growth');
	const fmt = {
		count: (n) => n.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
		money: (n) => '$' + Math.round(n).toLocaleString('en-US'),
		kw: (n) => n.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
	};
	function calc() {
		const k = 1 + (parseFloat(g.value) || 0) / 100;
		document.querySelectorAll('[data-show="growth"]').forEach((el) => { el.textContent = parseFloat(g.value) || 0; });
		document.querySelectorAll('#proj-table [data-base]').forEach((td) => {
			td.querySelector('[data-v]').textContent = fmt[td.dataset.kind](parseFloat(td.dataset.base) * k);
		});
	}
	g.addEventListener('input', calc);
	calc();
})();
</script>
