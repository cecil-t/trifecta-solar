<?php
use App\Csrf;

/**
 * The site layout: arrays of panels drawn on the satellite view (public/assets/js/layout.js). Save
 * stores the drawing and updates the build sheet's drawn arrays and module count.
 *
 * @var array       $o           opportunity
 * @var array       $d           design row
 * @var string      $base        /presales/{id}/designs/{designId}
 * @var array       $config      what layout.js needs (origin, module, gaps, saved layout, URLs)
 * @var array|null  $module      App\Layout::module()
 * @var array|null  $origin      lat, lon
 * @var string      $originNote
 * @var string|null $who         who saved the layout last
 */
$inch = static fn (float $m): string => rtrim(rtrim(number_format($m / 0.0254, 2, '.', ''), '0'), '.');
?>
<div class="page-head layout-head">
	<div>
		<a href="<?= e($base) ?>" class="back">&larr; <?= e($d['name']) ?></a>
		<div class="page-kicker">Site layout</div>
		<h1><?= e($o['name']) ?></h1>
		<p class="muted small">
			<?= $d['layout_at'] ? 'Saved ' . e(fmt_dt($d['layout_at'], 'm/d/Y g:i A')) . ($who ? ' by ' . e($who) : '') : 'Not drawn yet' ?>
			<?= $originNote !== '' ? ' &middot; ' . e($originNote) : '' ?>
		</p>
	</div>
	<?php if ($origin && $module): ?>
		<form method="post" action="<?= e($base) ?>/layout" class="head-actions" id="layout-form">
			<?= Csrf::field() ?>
			<input type="hidden" name="layout" id="layout-json" value="">
			<span class="small layout-dirty" id="layout-dirty" hidden>Unsaved changes</span>
			<button class="btn btn-primary" name="then" value="">Save</button>
			<button class="btn btn-secondary" name="then" value="sheet">Save and return to build sheet</button>
		</form>
	<?php endif; ?>
</div>

<?php if (!$origin): ?>
	<section class="card">
		<h2>No map point yet</h2>
		<p>The layout starts from the opportunity's map point. <a href="/presales/<?= (int) $o['id'] ?>/edit">Edit the opportunity</a>, check the site address and find its map point, then come back.</p>
	</section>
<?php elseif (!$module): ?>
	<section class="card">
		<h2>No module on this build sheet</h2>
		<p>The layout draws the build sheet's module. <a href="<?= e($base) ?>">Add a module line</a> first.</p>
	</section>
<?php else: ?>
	<div class="layout-app" id="layout-app" data-config="<?= e(json_encode($config)) ?>" data-csrf="<?= e(Csrf::token()) ?>">
		<div class="layout-toolbar" role="toolbar" aria-label="Layout tools">
			<div class="tool-group">
				<button type="button" class="tool is-active" data-tool="select" aria-pressed="true" title="Select an array or measurement to move, turn or edit it. Drag empty map to pan. (V)">Select</button>
				<button type="button" class="tool" data-tool="draw" aria-pressed="false" title="Click and drag a box; it fills with panels as it grows. On a Google roof face it lines up with that face. (A)">Draw array</button>
				<button type="button" class="tool" data-tool="panels" aria-pressed="false" title="Click or drag over panels to remove them, or over empty spots next to an array to add them. (P)">Add / remove panels</button>
				<button type="button" class="tool" data-tool="measure" aria-pressed="false" title="Drag from one point to another (or click both). Measures along the roof's slope, rounded down to the inch. (M)">Measure</button>
			</div>
			<div class="tool-group tool-new">
				<span class="small muted">New arrays</span>
				<label id="new-mod-wrap" hidden><span class="sr-only">Module</span>
					<select id="new-mod"></select>
				</label>
				<label><span class="sr-only">Panel orientation</span>
					<select id="new-orient">
						<option value="portrait">Portrait</option>
						<option value="landscape">Landscape</option>
					</select>
				</label>
				<label><span class="sr-only">Mount</span>
					<select id="new-mount">
						<option value="roof">Roof</option>
						<option value="ground">Ground</option>
						<option value="tracker">Tracker</option>
					</select>
				</label>
				<label class="tool-pitch">Pitch <input type="text" id="new-pitch" value="6/12" inputmode="decimal" title="A roof pitch like 6/12, or a plain number for degrees. Arrays drawn on a Google roof face take its pitch instead."></label>
			</div>
			<div class="tool-group">
				<button type="button" class="btn btn-small btn-secondary" id="btn-roof" title="Ask Google for the roof faces of the building in the middle of the map">Roof data</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-roof-toggle" title="Show or hide Google's roof faces on the map" hidden>Hide faces</button>
				<button type="button" class="btn btn-small btn-secondary" id="btn-pview" title="Choose what the proposal's aerial shows: move and zoom the map under a frame the shape of the proposal's picture">Proposal view</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-undo" title="Undo (Ctrl+Z)" disabled>Undo</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-redo" title="Redo (Ctrl+Y)" disabled>Redo</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-fit" title="Zoom to the arrays">Fit</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-zoom-in" title="Zoom in" aria-label="Zoom in">+</button>
				<button type="button" class="btn btn-small btn-ghost" id="btn-zoom-out" title="Zoom out" aria-label="Zoom out">&minus;</button>
			</div>
		</div>
		<div class="layout-main">
			<div class="layout-map" id="layout-map">
				<svg id="layout-svg" class="layout-svg" xmlns="http://www.w3.org/2000/svg" role="application" aria-label="Satellite map with the panel layout" tabindex="0">
					<g id="layer-base"></g>
					<g id="layer-tiles"></g>
					<g id="layer-roof"></g>
					<g id="layer-arrays"></g>
					<g id="layer-measures"></g>
					<g id="layer-draft"></g>
					<g id="layer-handles"></g>
				</svg>
				<div class="layout-hint" id="layout-hint" role="status"></div>
				<div class="pview-frame" id="pview-frame" hidden></div>
				<div class="pview-bar" id="pview-bar" hidden>
					<span class="small" id="pview-note">Move and zoom the map so the dashed frame shows what the proposal should show.</span>
					<span class="pview-actions">
						<button type="button" class="btn btn-small btn-primary" id="pview-save">Save proposal view</button>
						<button type="button" class="btn btn-small btn-ghost" id="pview-auto" title="Go back to framing the proposal's aerial on the arrays">Automatic view</button>
						<button type="button" class="btn btn-small btn-ghost" id="pview-cancel">Cancel</button>
					</span>
				</div>
				<img class="layout-glogo" src="<?= asset('assets/img/google-maps-logo.svg') ?>" alt="Google Maps" width="86" height="18">
				<div class="layout-scale" id="layout-scale"><span id="layout-scale-bar"></span><span id="layout-scale-text"></span></div>
				<div class="layout-attrib" id="layout-attrib"></div>
			</div>
			<aside class="layout-side">
				<div class="layout-box">
					<p class="layout-totals" id="layout-totals"></p>
					<p class="layout-yield" id="layout-yield" role="status" hidden></p>
					<p class="small muted" id="layout-yield-basis" hidden></p>
					<ul class="layout-modcounts small" id="layout-modcounts"></ul>
					<p class="small text-red" id="layout-module-note" hidden></p>
				</div>
				<section class="layout-box" id="sel-box">
					<h2>Selected array</h2>
					<p class="small muted" id="sel-none">Click an array to edit it.</p>
					<div class="layout-fields" id="sel-fields" hidden>
						<label class="span-2">Name <input type="text" id="sel-name" maxlength="60"></label>
						<label>Mount
							<select id="sel-mount">
								<option value="roof">Roof</option>
								<option value="ground">Ground</option>
								<option value="tracker">Tracker</option>
							</select>
						</label>
						<label id="sel-material-wrap">Roof material
							<select id="sel-material"></select>
						</label>
						<label class="span-2" id="sel-mod-wrap" hidden>Module
							<select id="sel-mod"></select>
						</label>
						<label>Panels
							<select id="sel-orient">
								<option value="portrait">Portrait</option>
								<option value="landscape">Landscape</option>
							</select>
						</label>
						<label>Shading (%) <input type="text" id="sel-shade" inputmode="decimal" placeholder="0" title="Share of a year's production lost to shade on this array"></label>
						<label><span id="sel-pitch-label">Pitch</span> <input type="text" id="sel-pitch" inputmode="decimal" title="A roof pitch like 6/12, or a plain number for degrees"></label>
						<label>Azimuth (&deg;) <input type="text" id="sel-az" inputmode="decimal" title="The way the panels face, degrees from true north (180 = south). Turning the array changes it."></label>
						<label class="span-2" id="sel-face-wrap" hidden>Pitch from Google roof face
							<select id="sel-face"></select>
						</label>
						<p class="small muted span-2" id="sel-pitch-note"></p>
						<label class="span-2">Shading note <input type="text" id="sel-shade-note" maxlength="200" placeholder="How it was judged: trees, chimney, Solar API, site visit"></label>
						<p class="small span-2" id="sel-stats"></p>
						<div class="span-2 layout-sel-actions">
							<button type="button" class="btn btn-small btn-secondary" id="sel-copy" title="Copy this array beside it (Ctrl+D)">Duplicate</button>
							<button type="button" class="btn btn-small btn-ghost" id="sel-flip" title="Turn it around so the panels face the other way, in the same spot">Flip facing</button>
							<button type="button" class="btn btn-small btn-ghost" id="sel-delete">Delete array</button>
						</div>
					</div>
				</section>
				<section class="layout-box">
					<h2>Arrays</h2>
					<ul class="layout-list" id="array-list"></ul>
				</section>
				<section class="layout-box">
					<h2>Measurements</h2>
					<p class="small muted">Rounded down to the inch. Yellow ones are stretched by the roof's slope (measured up the roof); blue ones are flat (across the slope, or no slope). The slope comes from the array or Google roof face under it, or a pitch you type. Click one to move its ends or change its slope.</p>
					<ul class="layout-list" id="measure-list"></ul>
					<div class="layout-fields mt-xs" id="msel" hidden>
						<label class="span-2">Slope
							<select id="msel-slope"></select>
						</label>
						<label class="span-2" id="msel-pitch-wrap" hidden>Pitch (up the line) <input type="text" id="msel-pitch" inputmode="decimal" placeholder="6/12"></label>
						<div class="span-2 layout-sel-actions">
							<button type="button" class="btn btn-small btn-ghost" id="msel-delete">Delete measurement</button>
						</div>
					</div>
				</section>
				<section class="layout-box">
					<h2>Google roof data</h2>
					<p class="small muted" id="roof-info">Center the map on the roof, then click Roof data. Google's roof faces show as numbered outlines; click one (here or on the map) to highlight it.</p>
					<ul class="layout-list" id="roof-list"></ul>
				</section>
				<section class="layout-box">
					<h2>Modules and spacing</h2>
					<div id="module-list"></div>
					<div class="layout-fields">
						<label>Panel gap (in) <input type="text" id="gap-panel" inputmode="decimal" value="<?= e($inch($config['gap']['panel'])) ?>"></label>
						<label>Row gap (in) <input type="text" id="gap-row" inputmode="decimal" value="<?= e($inch($config['gap']['row'])) ?>"></label>
					</div>
					<p class="small muted">Modules come from the build sheet and their size from the Sales catalog. Gaps are on the roof, not as seen from above.</p>
				</section>
				<details class="layout-box layout-help">
					<summary>Tips and shortcuts</summary>
					<ul class="small">
						<li><strong>Draw:</strong> click and drag a box. Started on a Google roof face, it lines up with the face and takes its pitch and direction; otherwise it lines up with the last array (or faces south). Turn it with the round handle, or Flip facing.</li>
						<li><strong>Pitch</strong> shortens panels as seen from above (a 6/12 roof shows a 66 in panel as 59 in), so set it before judging the fit. Type 6/12, or a plain number for degrees (no degree sign needed).</li>
						<li><strong>Pan:</strong> right-click and drag with any tool (or drag empty map with Select, or hold the space bar). <strong>Zoom:</strong> mouse wheel.</li>
						<li><strong>Keys:</strong> V select, A draw, P panels, M measure, Esc cancel, Delete removes the selected array or measurement, arrows nudge 1 in (Shift: 1 ft), [ and ] turn it 0.5&deg; (Shift: 5&deg;), Ctrl+D duplicates, Ctrl+Z undo.</li>
						<li><strong>Proposal view</strong> sets what the proposal's aerial shows: drag and zoom the map under the dashed frame (to show the house, barn or tree line too), then Save proposal view. Automatic view goes back to framing the arrays.</li>
						<li><strong>Trackers</strong> are drawn flat, with a dashed red circle for the space the table sweeps as it turns.</li>
						<li>Satellite photos aren't perfect: tall roofs lean a little, and roof edges include the gutter and overhang.</li>
					</ul>
				</details>
			</aside>
		</div>
	</div>
	<script src="<?= asset('assets/js/layout.js') ?>"></script>
<?php endif; ?>
