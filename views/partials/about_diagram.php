<?php
/**
 * About page: a static picture of how the pieces fit together (who reaches the app, what runs where,
 * which scheduled jobs talk to which outside services). Drawn as inline SVG, styled from app.css.
 * Names that depend on the server (its model, the certificate issuer) come from what the page detects,
 * so nothing about the live setup is written into the repo.
 *
 * @var ?string $model   server maker and model (App\ServerInfo), null when unknown
 * @var ?string $issuer  organization that issued the HTTPS certificate, null when none answered
 */
$box = static function (string $kind, int $x, int $y, int $w, array $lines, int $h = 0): string {
	$h = $h ?: (count($lines) > 2 ? 72 : (count($lines) === 2 ? 56 : 34));
	$cx = $x + $w / 2;
	$out = '<rect class="arch-' . $kind . '" x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="8"/>';
	$first = count($lines) === 1 ? $y + $h / 2 : $y + 18;
	foreach (array_values($lines) as $i => $line) {
		$cls = $i === 0 ? (count($lines) === 1 ? 'arch-n' : 'arch-t') : 'arch-s';
		$out .= '<text class="' . $cls . '" x="' . $cx . '" y="' . ($first + $i * 19) . '" text-anchor="middle" dominant-baseline="central">' . e($line) . '</text>';
	}
	return $out;
};
$arrow = static fn (string $d): string => '<path class="arch-arr" d="' . $d . '" marker-end="url(#arch-arrow)"/>';
?>
<div class="arch-wrap">
	<svg class="arch" viewBox="0 0 760 800" role="img" aria-labelledby="arch-title arch-desc">
		<title id="arch-title">How the Ops Tracker fits together</title>
		<desc id="arch-desc">Staff browsers and the office display reach a reverse proxy on the server, which hands requests to the Docker container running the tracker code, SAM and WeasyPrint, with the database, weather files and portal sessions in persistent storage. Scheduled jobs on the server pull code updates from GitHub, copy backups offsite, poll the inverter portals and check the company website's quote form for new requests. The tracker calls proposal data services, address and weather lookups, and the Claude API (which reads electric bills) as people use it and when the website check finds a bill.</desc>
		<defs>
			<marker id="arch-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
				<path class="arch-head" d="M2 1L8 5L2 9"/>
			</marker>
		</defs>

		<?= $box('ext', 40, 40, 150, [$issuer ?: 'Certificate authority', 'HTTPS certificate']) ?>
		<?= $box('node', 210, 40, 170, ['Staff browsers', 'Desktop, phone app']) ?>
		<?= $box('node', 400, 40, 170, ['Office display', 'Display link, no login']) ?>

		<rect class="arch-host" x="40" y="150" width="680" height="430" rx="18"/>
		<text class="arch-t" x="700" y="172" text-anchor="end" dominant-baseline="central"><?= e($model ?: 'Server') ?></text>
		<text class="arch-s" x="700" y="190" text-anchor="end" dominant-baseline="central">Linux server</text>

		<?= $arrow('M115 205 L115 98') ?>
		<?= $arrow('M295 96 L295 120 L180 120 L180 203') ?>
		<?= $arrow('M485 96 L485 132 L210 132 L210 203') ?>

		<?= $box('node', 60, 205, 170, ['Reverse proxy', 'HTTPS for the app']) ?>

		<rect class="arch-ctr" x="260" y="205" width="440" height="250" rx="12"/>
		<text class="arch-t" x="480" y="227" text-anchor="middle" dominant-baseline="central">Docker container</text>
		<text class="arch-s" x="480" y="245" text-anchor="middle" dominant-baseline="central">Apache web server, PHP</text>
		<?= $arrow('M230 233 L248 233 L248 290 L278 290') ?>

		<?= $box('app', 280, 262, 170, ['Tracker code', 'Pages, imports, jobs']) ?>
		<?= $box('app', 280, 330, 170, ['SAM, WeasyPrint', 'Production, PDFs, photos']) ?>
		<?= $arrow('M450 290 L468 290') ?>

		<rect class="arch-store" x="470" y="262" width="210" height="178" rx="8"/>
		<text class="arch-t" x="575" y="280" text-anchor="middle" dominant-baseline="central">Persistent storage</text>
		<text class="arch-s" x="575" y="297" text-anchor="middle" dominant-baseline="central">Survives rebuilds</text>
		<?= $box('app', 484, 312, 182, ['SQLite database']) ?>
		<?= $box('app', 484, 352, 182, ['Weather files']) ?>
		<?= $box('app', 484, 392, 182, ['Portal sessions']) ?>

		<text class="arch-l" x="60" y="480" dominant-baseline="central">Scheduled jobs (cron)</text>
		<?= $box('node', 60, 495, 155, ['Code update', 'Hourly', '8 AM to 4 PM'], 72) ?>
		<?= $box('node', 223, 495, 155, ['Backup', 'Daily at 4 AM', 'Verified snapshot'], 72) ?>
		<?= $box('node', 386, 495, 155, ['Monitor poll', 'Every 15 minutes', 'Within API limits'], 72) ?>
		<?= $box('node', 549, 495, 155, ['Website poll', 'Weekdays 8 AM to 6 PM', 'Weekends at noon'], 72) ?>
		<?= $arrow('M200 495 L200 470 L290 470 L290 457') ?>
		<?= $arrow('M300 495 L300 457') ?>
		<?= $arrow('M463 495 L463 457') ?>
		<?= $arrow('M626 495 L626 457') ?>
		<?= $arrow('M138 567 L138 598') ?>
		<?= $arrow('M300 567 L300 598') ?>
		<?= $arrow('M463 567 L463 598') ?>
		<?= $arrow('M626 567 L626 598') ?>

		<?= $box('ext', 60, 600, 155, ['GitHub', 'Pull from main', 'Rebuild when needed']) ?>
		<?= $box('ext', 223, 600, 155, ['Offsite backup', 'Copies of the', 'daily snapshots']) ?>
		<?= $box('ext', 386, 600, 155, ['Inverter portals', 'SolarEdge, Enphase', 'SMA, APsystems, Growatt']) ?>
		<?= $box('ext', 549, 600, 155, ['Company website', 'Quote request form', 'and uploaded bills']) ?>

		<?= $arrow('M700 228 L740 228 L740 692 L620 692 L620 708') ?>
		<?= $arrow('M620 692 L385 692 L385 708') ?>
		<?= $arrow('M385 692 L150 692 L150 708') ?>
		<?= $box('ext', 40, 710, 225, ['Proposal data', 'NSRDB weather, OpenEI rates', 'Google Maps, Solar API, tiles']) ?>
		<?= $box('ext', 280, 710, 210, ['Lookups', 'Census and OpenStreetMap', 'Open-Meteo weather']) ?>
		<?= $box('ext', 505, 710, 215, ['Claude API (Anthropic)', 'Reads electric bills', 'Uploads and website requests']) ?>
	</svg>
</div>
