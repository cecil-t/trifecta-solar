<?php
declare(strict_types=1);

namespace App;

/** Small inline SVG icons drawn in currentColor on a 24x24 grid. */
final class Icons
{
	/** Inner SVG markup per icon. */
	private const SHAPES = [
		// house with a panel lying on the right-hand roof slope
		'roof' => '<path d="M2 13.5 11 5l10 8.5"/><path d="M4.5 11.3V20h14v-8.5"/><path d="M10 20v-4.5h3V20"/><path class="fill" d="m13.5 5.15 7.6 6.5 1.7-2-7.6-6.5z"/>',
		// tilted panel on two legs, ground line
		'ground' => '<path class="fill" d="m2.5 12.5 14-6.5 1.7 3.5-14 6.5z"/><path d="M7 15v5M15.5 9.5V20M2 20h20"/>',
		// panel on a single post, with the arc it follows
		'tracker' => '<path class="fill" d="M5 11.8 18.2 8.3l.8 3.1-13.2 3.5z"/><path d="M12 13v7M8 20h8"/><path d="M4.5 6.5a10 10 0 0 1 15 0"/><path d="m17.2 4.4 2.3 2.1-.3-3.1"/>',
		// battery with a charge bolt
		'battery' => '<rect x="2.5" y="7" width="16.5" height="10" rx="2"/><path d="M21.5 10.5v3"/><path class="fill" d="M11.6 8.6 7.8 12.6h3l-1 2.8 3.8-4h-3z"/>',
	];

	public static function svg(string $name, string $label, string $class = ''): string
	{
		$label = e($label);
		return '<svg class="icon icon-' . $name . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" role="img" aria-label="' . $label . '"><title>' . $label . '</title>' . self::SHAPES[$name] . '</svg>';
	}

	/** The install-type and battery icons for a project row, or '' when there are none. */
	public static function scope(array $p): string
	{
		$out = '';
		if ($p['install_type'] && !empty($p['has_pv'] ?? 1) && isset(Projects::INSTALL_TYPES[$p['install_type']])) {
			$out .= self::svg($p['install_type'], Projects::INSTALL_TYPES[$p['install_type']]);
		}
		if (!empty($p['has_batteries'])) {
			$kwh = $p['batt_kwh'] ? ' ' . rtrim(rtrim(number_format((float) $p['batt_kwh'], 1), '0'), '.') . ' kWh' : '';
			$out .= self::svg('battery', 'Batteries' . $kwh);
		}
		return $out;
	}
}
