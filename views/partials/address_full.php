<?php
/**
 * One-line address entry (public/assets/js/address.js): type or paste the whole address and Look up
 * fills the split fields below it (data-addr). Goes inside a .grid-form.
 *
 * @var string $value
 */
?>
<div class="checkfield span-all addr-box" data-address="/address/lookup">
	<label for="addr-full" class="field-label">Full address</label>
	<div class="addr-row">
		<input type="text" id="addr-full" class="addr-full" value="<?= e($value) ?>" autocomplete="off" placeholder="Type or paste the whole address, e.g. 123 Main St, Manheim, PA 17545">
		<button type="button" class="btn btn-secondary btn-small addr-go">Look up</button>
	</div>
	<small class="hint">Any order works, including a monitoring portal's "Main St 12, Town, Pennsylvania, United States, 17601". It fills the fields below; fix any of them by hand.</small>
	<div class="addr-note geo-note" aria-live="polite" hidden></div>
</div>
