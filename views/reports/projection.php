<?php
require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
?>
<section class="card card-flush report-card-body">
    <p class="hint report-hint report-hint-top">Each window's pace, scaled to 12 months. Windows end on the as-of date.</p>
    <table class="table">
        <thead><tr><th>Based on</th><th class="num">Actual in window</th><th class="num">Projects / yr</th><th class="num">Contract value / yr</th><th class="num">kW DC / yr</th></tr></thead>
        <tbody>
        <?php foreach ($projections as $label => $pr): ?>
            <tr>
                <td><?= e($label) ?><div class="muted small"><?= e(fmt_date($pr['from'])) ?> to <?= e(fmt_date($pr['to'])) ?></div>
                    <?php if ($pr['partial']): ?><span class="chip chip-orange" title="The tracker has no signed projects before <?= e(fmt_date($earliest)) ?>, so this window is missing earlier sales and reads low">Incomplete history</span><?php endif; ?>
                </td>
                <td class="num small"><?= $pr['actual']['count'] ?> &middot; <?= $money($pr['actual']['price']) ?> &middot; <?= $kwf($pr['actual']['kw']) ?> kW</td>
                <td class="num"><?= number_format($pr['next12']['count'], 1) ?></td>
                <td class="num"><?= $money($pr['next12']['price']) ?></td>
                <td class="num"><?= $kwf($pr['next12']['kw']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint report-hint">The tracker holds the 2026 tab plus a few older completed jobs, so windows reaching back into 2025 are missing sales that finished before 2026.</p>
</section>
