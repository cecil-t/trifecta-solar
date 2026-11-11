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
	<svg class="arch" viewBox="0 0 680 800" role="img" aria-labelledby="arch-title arch-desc">
		<title id="arch-title">How the Ops Tracker fits together</title>
		<desc id="arch-desc">Staff browsers and the office display reach a reverse proxy on the server, which hands requests to the Docker container running the tracker code, SAM and WeasyPrint, with the database, weather files and portal sessions in persistent storage. Scheduled jobs on the server pull code updates from GitHub, copy backups offsite and poll the inverter portals. The tracker calls proposal data services and address and weather lookups as people use it.</desc>
		<defs>
			<marker id="arch-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
				<path class="arch-head" d="M2 1L8 5L2 9"/>
			</marker>
		</defs>

		<?= $box('ext', 40, 40, 150, [$issuer ?: 'Certificate authority', 'HTTPS certificate']) ?>
		<?= $box('node', 210, 40, 170, ['Staff browsers', 'Desktop, phone app']) ?>
		<?= $box('node', 400, 40, 170, ['Office display', 'Display link, no login']) ?>

		<rect class="arch-host" x="40" y="150" width="580" height="420" rx="18"/>
		<text class="arch-t" x="600" y="172" text-anchor="end" dominant-baseline="central"><?= e($model ?: 'Server') ?></text>
		<text class="arch-s" x="600" y="190" text-anchor="end" dominant-baseline="central">Linux server</text>

		<?= $arrow('M115 205 L115 98') ?>
		<?= $arrow('M295 96 L295 120 L180 120 L180 203') ?>
		<?= $arrow('M485 96 L485 132 L210 132 L210 203') ?>

		<?= $box('node', 60, 205, 170, ['Reverse proxy', 'HTTPS for the app']) ?>

		<rect class="arch-ctr" x="260" y="205" width="340" height="250" rx="12"/>
		<text class="arch-t" x="430" y="227" text-anchor="middle" dominant-baseline="central">Docker container</text>
		<text class="arch-s" x="430" y="245" text-anchor="middle" dominant-baseline="central">Apache web server, PHP</text>
		<?= $arrow('M230 233 L248 233 L248 290 L278 290') ?>

		<?= $box('app', 280, 262, 145, ['Tracker code', 'Pages, imports, jobs']) ?>
		<?= $box('app', 280, 330, 145, ['SAM, WeasyPrint', 'Production, PDFs']) ?>
		<?= $arrow('M425 290 L438 290') ?>

		<rect class="arch-store" x="440" y="262" width="150" height="178" rx="8"/>
		<text class="arch-t" x="515" y="280" text-anchor="middle" dominant-baseline="central">Persistent storage</text>
		<text class="arch-s" x="515" y="297" text-anchor="middle" dominant-baseline="central">Survives rebuilds</text>
		<?= $box('app', 452, 312, 126, ['SQLite database']) ?>
		<?= $box('app', 452, 352, 126, ['Weather files']) ?>
		<?= $box('app', 452, 392, 126, ['Portal sessions']) ?>

		<text class="arch-l" x="60" y="482" dominant-baseline="central">Scheduled jobs (cron)</text>
		<?= $box('node', 60, 495, 170, ['Code update', 'Hourly, 8 AM to 4 PM']) ?>
		<?= $box('node', 240, 495, 170, ['Backup', 'Daily at 4 AM']) ?>
		<?= $box('node', 420, 495, 170, ['Monitor poll', 'Every 15 minutes']) ?>
		<?= $arrow('M220 495 L220 468 L280 468 L280 457') ?>
		<?= $arrow('M325 495 L325 457') ?>
		<?= $arrow('M505 495 L505 457') ?>
		<?= $arrow('M145 551 L145 598') ?>
		<?= $arrow('M325 551 L325 598') ?>
		<?= $arrow('M505 551 L505 598') ?>

		<?= $box('ext', 60, 600, 170, ['GitHub', 'Pull from main', 'Rebuild when needed']) ?>
		<?= $box('ext', 240, 600, 170, ['Offsite backup', 'Copies of the', 'daily snapshots']) ?>
		<?= $box('ext', 420, 600, 170, ['Inverter portals', 'SolarEdge, Enphase', 'SMA, APsystems, Growatt']) ?>

		<?= $arrow('M600 228 L630 228 L630 692 L490 692 L490 708') ?>
		<?= $arrow('M490 692 L190 692 L190 708') ?>
		<?= $box('ext', 40, 710, 300, ['Proposal data', 'NSRDB weather, OpenEI utility rates', 'Google Maps, Solar API, map tiles']) ?>
		<?= $box('ext', 360, 710, 260, ['Lookups', 'Census and OpenStreetMap addresses', 'Open-Meteo dashboard weather']) ?>
	</svg>
</div>
