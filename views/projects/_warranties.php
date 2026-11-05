<?php
use App\Csrf;
use App\Projects;
use App\Warranties;

/**
 * Warranties card on the project page.
 * @var array $p        project row
 * @var array $lines    Warranties::forProject()
 * @var array $program  Warranties::program()
 * @var array $stored   project_warranties rows by id
 */
$base = '/projects/' . (int) $p['id'];
$years = static fn ($y) => rtrim(rtrim(number_format((float) $y, 1), '0'), '.');
$fields = static function (?array $w) use ($years): string {
	$v = static fn (string $k) => $w[$k] ?? '';
	$opt = static fn (array $list, string $sel) => implode('', array_map(static fn ($k, $l) => '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($list), $list));
	return '<label>Kind <select name="kind" required>' . ($w ? '' : '<option value="">Pick one</option>') . $opt(Warranties::KINDS, (string) $v('kind')) . '</select></label>'
		. '<label class="grow">Provider <input type="text" name="provider" value="' . e((string) $v('provider')) . '" maxlength="120" placeholder="e.g. SEG Solar"></label>'
		. '<label class="grow">Covers <input type="text" name="covers" value="' . e((string) $v('covers')) . '" maxlength="200" placeholder="e.g. 87.4% output at 30 years"></label>'
		. '<label class="short">Years <input type="text" name="term_years" value="' . e($w ? $years($w['term_years']) : '') . '" inputmode="decimal" required></label>'
		. '<label>Starts at <select name="start_basis">' . $opt(Warranties::BASES, (string) ($v('start_basis') ?: 'install')) . '</select></label>'
		. '<label>Start date <input type="date" name="start_date" value="' . e((string) $v('start_date')) . '" title="Leave blank to use the step date"></label>'
		. '<label class="short">Cost ($) <input type="text" name="cost" value="' . ($w && $w['cost_cents'] !== null ? e(number_format($w['cost_cents'] / 100, 2, '.', '')) : '') . '" inputmode="decimal" placeholder="If we paid"></label>'
		. '<label class="grow">Reference <input type="text" name="reference" value="' . e((string) $v('reference')) . '" maxlength="120" placeholder="Registration #"></label>'
		. '<label class="grow">Guaranteed kWh <input type="text" name="guarantee_kwh" value="' . ($w && $w['guarantee_kwh'] !== null ? e(number_format((float) $w['guarantee_kwh'], 0, '.', '')) : '') . '" inputmode="numeric" placeholder="Production guarantee only"></label>'
		. '<label class="short">Payout $/kWh <input type="text" name="guarantee_rate" value="' . ($w && $w['guarantee_rate'] !== null ? e((string) (float) $w['guarantee_rate']) : '') . '" inputmode="decimal" placeholder="0.15"></label>'
		. '<label class="grow-2">Note <input type="text" name="note" value="' . e((string) $v('note')) . '" maxlength="500"></label>';
};
?>
<section class="card card-flush mt" id="warranties">
	<div class="phase-title">
		<h2>Warranties</h2>
		<span class="muted small">Program: <strong><?= e(Warranties::PROGRAMS[$program['code']]) ?></strong> (<?= e(Warranties::lc($program['how'])) ?>)<?= $p['is_legacy'] ? ' &middot; Legacy record' : '' ?></span>
	</div>
	<table class="table table-warranties">
		<thead><tr><th>Warranty</th><th>Covers</th><th class="num">Years</th><th>Starts</th><th>Ends</th><th>Left</th><th>Reference</th><th></th></tr></thead>
		<tbody>
		<?php if (!$lines): ?><tr><td colspan="8" class="empty">No warranties: the program is <?= e(strtolower(Warranties::PROGRAMS[$program['code']])) ?>.</td></tr><?php endif; ?>
		<?php foreach ($lines as $l): ?>
			<tr class="w-<?= e($l['status']) ?>">
				<td><strong><?= e($l['label']) ?></strong><?= $l['provider'] && $l['provider'] !== $l['label'] ? '<div class="muted small">' . e($l['provider']) . '</div>' : '' ?>
					<?php if ($l['flag']): ?><div><span class="chip chip-orange"><?= e($l['flag']) ?></span></div><?php endif; ?></td>
				<td class="small"><?= e((string) $l['covers']) ?><?php if ($l['guarantee_kwh'] !== null): ?><div><?= number_format($l['guarantee_kwh']) ?> kWh guaranteed<?= $l['guarantee_rate'] !== null ? ', $' . e(number_format($l['guarantee_rate'], 2)) . '/kWh short' : '' ?></div><?php endif; ?><?php if ($l['note']): ?><div class="muted"><?= e($l['note']) ?></div><?php endif; ?></td>
				<td class="num"><?= e($years($l['years'])) ?><?= $l['assumed'] ? '<span class="muted" title="Assumed">*</span>' : '' ?></td>
				<td class="nowrap"><?php if ($l['start']): ?><?= e(Warranties::month($l['start'], $l['start_tag'])) ?><div class="muted small" title="<?= e($l['start_how']) ?>"><?= e(Warranties::TAGS[$l['start_tag']]) ?>: <?= e(Warranties::lc($l['start_how'])) ?></div><?php else: ?><span class="muted"><?= e($l['start_how']) ?></span><?php endif; ?></td>
				<td class="nowrap"><?= e(Warranties::month($l['end'], $l['start_tag'])) ?></td>
				<td class="nowrap"><?= $l['status'] === 'expired' ? '<span class="chip">Expired</span>' : e(Warranties::left($l['months_left'])) ?></td>
				<td class="small"><?= e((string) $l['reference']) ?><?= $l['cost_cents'] !== null ? '<div>' . e(Projects::money($l['cost_cents'])) . '</div>' : '' ?></td>
				<td class="nowrap"><?php if ($l['id']): ?><button type="button" class="btn btn-ghost btn-small" data-toggle-next-row>Edit</button><?php endif; ?></td>
			</tr>
			<?php if ($l['id'] && isset($stored[$l['id']])): ?>
				<tr class="visit-edit-row" hidden>
					<td colspan="8">
						<form method="post" action="<?= $base ?>/warranties/<?= (int) $l['id'] ?>" class="visit-add visit-edit-form">
							<?= Csrf::field() ?>
							<?= $fields($stored[$l['id']]) ?>
							<button class="btn btn-primary btn-small">Save</button>
							<button class="btn btn-ghost btn-small text-red" formaction="<?= $base ?>/warranties/<?= (int) $l['id'] ?>/delete" data-confirm="Remove this warranty?">Remove</button>
						</form>
					</td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="hint report-hint">Starts: <strong>actual</strong> is the step's own date or a typed date, <strong>derived</strong> is worked out from a neighboring step, <strong>~estimated</strong> is contract signed plus <?= Warranties::LAG_MONTHS ?> months. Trifecta's 10 years start when final inspection passes. * Years assumed.</p>
	<form method="post" action="<?= $base ?>/warranty" class="visit-add">
		<?= Csrf::field() ?>
		<label class="warranty-program">Warranty program
			<select name="warranty_program">
				<option value="">From the SolarInsure question</option>
				<?php foreach (Warranties::PROGRAMS as $k => $label): ?><option value="<?= $k ?>" <?= ($p['warranty_program'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
			</select>
		</label>
		<label>Program start date <input type="date" name="warranty_start" value="<?= e((string) $p['warranty_start']) ?>" title="Leave blank to use the step dates"></label>
		<button class="btn btn-secondary btn-small">Save program</button>
	</form>
	<details class="warranty-add">
		<summary class="btn btn-ghost btn-small">+ Add a warranty</summary>
		<form method="post" action="<?= $base ?>/warranties" class="visit-add">
			<?= Csrf::field() ?>
			<?= $fields(null) ?>
			<button class="btn btn-secondary btn-small">Add warranty</button>
		</form>
	</details>
</section>
