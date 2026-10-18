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
$rows = $blocks;
if ($canEdit) {
	for ($i = 0; $i < 3; $i++) {
		$rows[] = ['kind' => 'clarification', 'cond' => 'always', 'heading' => '', 'body' => '', 'sort_order' => '', 'new' => true];
	}
}
$sel = static fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<div class="page-head">
	<div>
		<a href="/presales/rates" class="back">&larr; Sales rates</a>
		<h1>Proposal terms</h1>
		<p class="muted">The opening, Clarifications, Terms &amp; Conditions, Final Authorizations and any addendum that print on every proposal, as blocks. Each block prints only when its condition holds for the build sheet, so one set covers in-house and SolarInsure jobs, the production guarantee and the rest.<?= $canEdit ? '' : ' Only admins can change them.' ?></p>
		<p class="muted small"><?= $version ? 'Current: version ' . (int) $version['id'] . ', saved ' . e(fmt_dt($version['created_at'])) . ($version['created_name'] ? ' by ' . e($version['created_name']) : '') . ($version['note'] ? ' (' . e($version['note']) . ')' : '') . '.' : 'No terms yet. Add them here or with bin/console import:terms.' ?></p>
	</div>
</div>

<details class="card band terms-help">
	<summary>How blocks work</summary>
	<ul class="small">
		<li>Blocks print in order by kind (opening, Clarifications, Terms &amp; Conditions, Final Authorizations, addendum), then by the order number. Section numbers are part of the heading you type.</li>
		<li>Text: a blank line starts a new paragraph, lines starting with "- " are bullets, and **two stars** around words make them bold.</li>
		<li>Placeholders fill in from the build sheet and the Rates page:
			<?php foreach (ProposalTerms::PLACEHOLDERS as $k => $label): ?><code>{<?= e($k) ?>}</code> <?= e($label) ?>; <?php endforeach; ?>
		</li>
		<li>Saving makes a new version. Every proposal footer names the terms version it printed with.</li>
	</ul>
</details>

<form method="post" action="/presales/terms" class="project-form">
	<?= Csrf::field() ?>
	<section class="card band">
		<h2>Blocks</h2>
		<div class="terms-rows">
			<?php foreach ($rows as $i => $b): ?>
				<div class="terms-row<?= !empty($b['new']) ? ' is-new' : '' ?>">
					<div class="terms-meta">
						<label>Order <input type="text" name="blocks[<?= $i ?>][sort]" value="<?= e((string) ($b['sort_order'] ?? '')) ?>" inputmode="numeric" <?= $dis ?>></label>
						<label>Block
							<select name="blocks[<?= $i ?>][kind]" <?= $dis ?>>
								<?php foreach (ProposalTerms::KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel($b['kind'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
							</select>
						</label>
						<label>Prints
							<select name="blocks[<?= $i ?>][cond]" <?= $dis ?>>
								<?php foreach (ProposalTerms::CONDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel($b['cond'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
							</select>
						</label>
						<?php if ($canEdit && empty($b['new'])): ?><label class="boxwrap"><input type="checkbox" name="blocks[<?= $i ?>][delete]" value="1"> Remove</label><?php endif; ?>
					</div>
					<div class="terms-text">
						<label>Heading <input type="text" name="blocks[<?= $i ?>][heading]" value="<?= e((string) ($b['heading'] ?? '')) ?>" placeholder="<?= !empty($b['new']) ? 'New block (optional heading)' : '' ?>" <?= $dis ?>></label>
						<label>Text <textarea name="blocks[<?= $i ?>][body]" rows="<?= max(2, min(14, (int) ceil(mb_strlen((string) ($b['body'] ?? '')) / 110) + substr_count((string) ($b['body'] ?? ''), "\n"))) ?>" <?= $dis ?>><?= e((string) ($b['body'] ?? '')) ?></textarea></label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
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
