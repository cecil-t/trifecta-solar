<?php
// Shared formatters for report views (included, not rendered as a partial).
$money = static fn (float $n) => '$' . number_format($n, 0);
$money2 = static fn (float $n) => '$' . number_format($n, 2);
$kwf = static fn (float $n) => number_format($n, 1);
$ppw = static fn (?float $n) => $n === null ? '' : '$' . number_format($n, 2);
$monthLabel = static fn (string $ym) => date('M Y', strtotime($ym . '-01'));
$salesRow = static function (string $label, array $s) use ($money, $kwf, $ppw): string {
	$note = $s['no_price'] ? '<span class="muted small no-price" title="Projects with no contract price">(' . $s['no_price'] . ' no price)</span> ' : '';
	return '<tr><td>' . $label . '</td><td class="num">' . $s['count'] . '</td><td class="num">' . $note . $money($s['price'])
		. '</td><td class="num">' . $kwf($s['kw']) . '</td><td class="num">' . $ppw($s['ppw']) . '</td></tr>';
};
