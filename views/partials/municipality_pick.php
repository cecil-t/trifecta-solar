<?php
/**
 * Municipality (AHJ) with its Census lookup note and the "add a municipality" fields, kept together
 * so the lookup's suggestion sits next to the choice it fills (public/assets/js/geo.js). Goes inside
 * a .grid-form.
 *
 * @var string $value           municipality_id, or 'new'
 * @var array  $municipalities
 * @var array  $counties
 * @var string $newName
 * @var string $newCounty
 * @var string $geoAttrs        extra data- attributes for the note (already escaped)
 */
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
?>
<div class="muni-block span-all">
	<label>Municipality (AHJ)
		<select name="municipality_id" data-reveal="new" data-target="#new-muni">
			<option value="">Choose</option>
			<option value="new" <?= $sel($value, 'new') ?>>+ Add a municipality</option>
			<?php foreach ($municipalities as $m): ?>
				<option value="<?= (int) $m['id'] ?>" <?= $sel($value, $m['id']) ?>><?= e(App\Municipalities::label($m)) ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<div id="geo-note" class="geo-note" aria-live="polite" hidden data-geo-url="/geo/lookup" <?= $geoAttrs ?>></div>
	<div id="new-muni" class="grid-form reveal muni-new" hidden>
		<label class="span-2">New municipality name <input type="text" name="new_muni_name" value="<?= e($newName) ?>" placeholder="e.g. Penn Township"></label>
		<label>County
			<select name="new_muni_county_id">
				<option value="">Choose</option>
				<?php foreach ($counties as $c): ?>
					<option value="<?= (int) $c['id'] ?>" <?= $sel($newCounty, $c['id']) ?>><?= e($c['name'] . ' (' . $c['state_code'] . ')') ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<small class="hint span-all">Saving adds this municipality to the list and links it here.</small>
	</div>
</div>
