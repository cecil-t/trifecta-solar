<?php
use App\Csrf;
use App\ProposalTerms;

/**
 * Sales > Proposal terms: the Clarifications and Terms & Conditions every proposal prints, as
 * blocks. Saving makes a new version (the history is below).
 *
 * @var ?array $version  current version
 * @var array  $blocks   its blocks (or as typed after a failed save)
 * @var string $note
 * @var array  $versions
 * @var bool   $canEdit  admins
 */
$dis = $canEdit ? '' : 'disabled';
$sel = static fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
// The page groups blocks by where they print; each kind gets a section
$sections = [
	'intro'         => ['Opening', 'A paragraph above the Clarifications bullets.'],
	'clarification' => ['Clarifications', 'One bullet each: what is and isn\'t included, and what the customer handles.'],
	'section'       => ['Terms & Conditions', 'The numbered contract sections. Put the number in the heading.'],
	'authorization' => ['Final Authorizations and Consents', 'Just above the signatures.'],
	'addendum'      => ['Addendum', 'Each prints on its own page with its own signature.'],
];
$byKind = array_fill_keys(array_keys($sections), []);
foreach ($blocks as $i => $b) {
	if (trim((string) ($b['heading'] ?? '')) === '' && trim((string) ($b['body'] ?? '')) === '') {
		continue;   // a blank "add" row coming back after a failed save
	}
	$k = isset($byKind[$b['kind'] ?? '']) ? $b['kind'] : 'clarification';
	$byKind[$k][$i] = $b;
}
$next = $blocks ? max(array_keys($blocks)) + 1 : 0;   // input index for the blank "add" rows
$preview = static function (array $b): string {
	$h = trim((string) ($b['heading'] ?? ''));
	if ($h !== '') {
		return $h;
	}
	$t = trim(preg_replace('/\s+/', ' ', str_replace('**', '', (string) ($b['body'] ?? ''))));
	return $t === '' ? '(empty)' : mb_strimwidth($t, 0, 110, '...');
};
$row = static function (int $i, array $b, string $kind, bool $new) use ($dis, $sel, $preview, $canEdit): void {
	$cond = (string) ($b['cond'] ?? 'always');
	$order = (string) ($b['sort_order'] ?? $b['sort'] ?? '');
	?>
	<details class="terms-block terms-<?= e($kind) ?><?= $new ? ' is-new' : '' ?>">
		<summary>
			<?php if ($new): ?>
				<span class="tb-title">+ Add a block here</span>
			<?php else: ?>
				<span class="tb-order"><?= e($order) ?></span>
				<span class="tb-title"><?= e($preview($b)) ?></span>
				<?php if ($cond !== 'always'): ?><span class="chip"><?= e(ProposalTerms::CONDS[$cond] ?? $cond) ?></span><?php endif; ?>
			<?php endif; ?>
		</summary>
		<div class="tb-fields">
			<div class="tb-meta">
				<label>Order <input type="text" name="blocks[<?= $i ?>][sort]" value="<?= e($order) ?>" inputmode="numeric" <?= $dis ?>></label>
				<label>Block
					<select name="blocks[<?= $i ?>][kind]" <?= $dis ?>>
						<?php foreach (ProposalTerms::KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel($kind, $k) ?>><?= e($label) ?></option><?php endforeach; ?>
					</select>
				</label>
				<label>Prints
					<select name="blocks[<?= $i ?>][cond]" <?= $dis ?>>
						<?php foreach (ProposalTerms::CONDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel($cond, $k) ?>><?= e($label) ?></option><?php endforeach; ?>
					</select>
				</label>
				<?php if ($canEdit && !$new): ?><label class="boxwrap"><input type="checkbox" name="blocks[<?= $i ?>][delete]" value="1" <?= !empty($b['delete']) ? 'checked' : '' ?>> Remove</label><?php endif; ?>
			</div>
			<div class="tb-text">
				<label>Heading <input type="text" name="blocks[<?= $i ?>][heading]" value="<?= e((string) ($b['heading'] ?? '')) ?>" placeholder="Optional" <?= $dis ?>></label>
				<label>Text <textarea name="blocks[<?= $i ?>][body]" rows="3" data-autogrow <?= $dis ?>><?= e((string) ($b['body'] ?? '')) ?></textarea></label>
			</div>
		</div>
	</details>
	<?php
};
?>
<div class="page-head">
	<div>
		<a href="/presales/rates" class="back">&larr; Sales rates</a>
		<h1>Proposal terms<?= help_link('proposal-terms') ?></h1>
		<p class="muted">The opening, Clarifications, Terms &amp; Conditions, Final Authorizations and any addendum that print on every proposal, as blocks. Each block prints only when its condition holds for the build sheet, so one set covers in-house and SolarInsure jobs, the production guarantee and the rest.<?= $canEdit ? '' : ' Only admins can change them.' ?></p>
		<p class="muted small"><?= $version ? 'Current: version ' . (int) $version['id'] . ', saved ' . e(fmt_dt($version['created_at'])) . ($version['created_name'] ? ' by ' . e($version['created_name']) : '') . ($version['note'] ? ' (' . e($version['note']) . ')' : '') . '.' : 'No terms yet. Add them here or with bin/console import:terms.' ?></p>
	</div>
</div>

<details class="card band terms-help">
	<summary>How blocks work</summary>
	<ul class="small">
		<li>Sections follow the proposal's order. Click a block to open it; the order number sorts blocks within their section, and section numbers are part of the heading you type. Changing a block's "Block" moves it to another section when you save.</li>
		<li>A tag on a block says when it prints; blocks without one always print.</li>
		<li>Text: a blank line starts a new paragraph, lines starting with "- " are bullets, and **two stars** around words make them bold.</li>
		<li>Placeholders fill in from the build sheet and the Rates page:
			<?php foreach (ProposalTerms::PLACEHOLDERS as $k => $label): ?><code>{<?= e($k) ?>}</code> <?= e($label) ?>; <?php endforeach; ?>
		</li>
		<li>Saving makes a new version. Every proposal footer names the terms version it printed with.</li>
	</ul>
</details>

<form method="post" action="/presales/terms" class="project-form">
	<?= Csrf::field() ?>
	<div class="terms-tools">
		<button type="button" class="btn btn-small btn-secondary" data-terms-all="open">Open all</button>
		<button type="button" class="btn btn-small btn-secondary" data-terms-all="close">Close all</button>
	</div>
	<?php foreach ($sections as $kind => [$label, $about]): ?>
		<section class="card band terms-kind">
			<div class="card-head card-head-plain band-head">
				<h2><?= e($label) ?></h2>
				<span class="small"><?= count($byKind[$kind]) ?> <?= count($byKind[$kind]) === 1 ? 'block' : 'blocks' ?></span>
			</div>
			<p class="hint"><?= e($about) ?></p>
			<div class="terms-list">
				<?php foreach ($byKind[$kind] as $i => $b) {
					$row($i, $b, $kind, false);
				} ?>
				<?php if ($canEdit) {
					$row($next++, ['cond' => 'always'], $kind, true);
				} ?>
			</div>
		</section>
	<?php endforeach; ?>
	<?php if ($canEdit): ?>
		<div class="form-actions">
			<label>What changed (optional) <input type="text" name="note" value="<?= e($note) ?>" maxlength="200"></label>
			<button class="btn btn-primary">Save as a new version</button>
		</div>
	<?php endif; ?>
</form>

<section class="card band">
	<h2>Versions</h2>
	<?php if ($versions): ?>
		<div class="table-wrap"><table class="table table-tight">
			<thead><tr><th>Version</th><th>Saved</th><th>By</th><th>Blocks</th><th>Note</th></tr></thead>
			<tbody>
			<?php foreach ($versions as $v): ?>
				<tr><td><?= (int) $v['id'] ?></td><td><?= e(fmt_dt($v['created_at'])) ?></td><td><?= e((string) $v['created_name']) ?></td><td class="num"><?= (int) $v['blocks'] ?></td><td><?= e((string) $v['note']) ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php else: ?>
		<p class="muted">None yet.</p>
	<?php endif; ?>
</section>
<script src="<?= asset('assets/js/terms.js') ?>"></script>
