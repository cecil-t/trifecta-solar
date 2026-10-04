<?php
declare(strict_types=1);

namespace App;

/**
 * A look under the covers for the About page: the machine the app runs on, read from /proc and
 * /sys (no shell commands). Inside Docker most of this is the host's, since a container shares the
 * host kernel. Anything that can't be read comes back null and is left off the page.
 */
final class ServerInfo
{
	public static function all(): array
	{
		$uptime = self::uptime();
		return [
			'model'     => self::model(),
			'cpu'       => self::cpu(),
			'temp_c'    => self::cpuTemp(),
			'memory'    => self::memory(),
			'container_memory' => self::containerMemory(),
			'load'      => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null,
			'uptime'    => $uptime,
			'container_started' => $uptime !== null ? self::containerStarted($uptime) : null,
			'processes' => count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []) ?: null,
			'kernel'    => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
		];
	}

	/**
	 * Maker and model, e.g. "Synology DS225+". DSM exposes the model in syno_hw_version, which only
	 * exists on Synology kernels; other machines fall back to the DMI (BIOS) vendor and product.
	 */
	private static function model(): ?string
	{
		$read = static function (string $f): ?string {
			$v = trim((string) @file_get_contents($f));
			return $v === '' || preg_match('/^(to be filled|default string|system (product name|manufacturer)|not specified|o\.e\.m)/i', $v) ? null : $v;
		};
		if ($syno = $read('/proc/sys/kernel/syno_hw_version')) {
			return 'Synology ' . $syno;
		}
		$product = $read('/sys/class/dmi/id/product_name');
		$vendor = $read('/sys/class/dmi/id/sys_vendor');
		if ($product === null) {
			return null;
		}
		return $vendor !== null && stripos($product, $vendor) !== 0 ? $vendor . ' ' . $product : $product;
	}

	/** ['name' => 'Intel Celeron J4125 CPU @ 2.00GHz', 'cores' => 4], or null. */
	private static function cpu(): ?array
	{
		$info = (string) @file_get_contents('/proc/cpuinfo');
		if ($info === '') {
			return null;
		}
		$name = preg_match('/^(?:model name|Hardware|Processor)\s*:\s*(.+)$/m', $info, $m) ? $m[1] : null;
		$name = $name ? trim((string) preg_replace(['/\((?:R|TM)\)/i', '/\s+/'], ['', ' '], $name)) : null;
		return ['name' => $name, 'cores' => preg_match_all('/^processor\s*:/m', $info) ?: null];
	}

	/** CPU package temperature in degrees C, from the thermal zones or hwmon sensors. */
	private static function cpuTemp(): ?float
	{
		$readings = [];
		foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $zone) {
			$type = trim((string) @file_get_contents($zone . '/type'));
			$t = @file_get_contents($zone . '/temp');
			if ($t !== false && is_numeric(trim($t))) {
				$readings[$type === 'x86_pkg_temp' ? 0 : 1][] = (int) $t / 1000;
			}
		}
		foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $mon) {
			if (in_array(trim((string) @file_get_contents($mon . '/name')), ['coretemp', 'k10temp', 'cpu_thermal'], true)) {
				$t = @file_get_contents($mon . '/temp1_input');
				if ($t !== false && is_numeric(trim($t))) {
					$readings[0][] = (int) $t / 1000;
				}
			}
		}
		ksort($readings);
		$best = reset($readings);
		return $best ? round(max($best), 1) : null;
	}

	/** Host memory in bytes: ['total' => , 'available' => ]. */
	private static function memory(): ?array
	{
		$info = (string) @file_get_contents('/proc/meminfo');
		if (!preg_match('/^MemTotal:\s+(\d+)/m', $info, $t)) {
			return null;
		}
		$avail = preg_match('/^MemAvailable:\s+(\d+)/m', $info, $a) ? (int) $a[1] * 1024 : null;
		return ['total' => (int) $t[1] * 1024, 'available' => $avail];
	}

	/** This container's memory use and limit in bytes (cgroup v2 or v1); limit null = none. */
	private static function containerMemory(): ?array
	{
		if (!is_file('/.dockerenv')) {
			return null;
		}
		$read = static fn (string $f): ?string => ($v = @file_get_contents($f)) === false ? null : trim($v);
		$used = $read('/sys/fs/cgroup/memory.current') ?? $read('/sys/fs/cgroup/memory/memory.usage_in_bytes');
		$limit = $read('/sys/fs/cgroup/memory.max') ?? $read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
		if ($used === null || !is_numeric($used)) {
			return null;
		}
		// "max" (v2) or a near-2^63 number (v1) both mean no limit
		$limit = is_numeric($limit) && (float) $limit < 1e15 ? (int) $limit : null;
		return ['used' => (int) $used, 'limit' => $limit];
	}

	/** Seconds since the (host) kernel booted. */
	private static function uptime(): ?float
	{
		$v = @file_get_contents('/proc/uptime');
		return $v !== false && preg_match('/^([\d.]+)/', $v, $m) ? (float) $m[1] : null;
	}

	/** When this container started: start time of its PID 1, in clock ticks after boot. */
	private static function containerStarted(float $uptime): ?int
	{
		if (!is_file('/.dockerenv')) {
			return null;
		}
		$stat = (string) @file_get_contents('/proc/1/stat');
		// Field 22 is starttime; skip past the ")" that closes the process name first.
		$fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));
		$ticks = $fields[19] ?? null;
		if (!is_numeric($ticks)) {
			return null;
		}
		// /proc reports in USER_HZ, which Linux fixes at 100 per second for userspace
		return (int) round(time() - $uptime + (int) $ticks / 100);
	}
}
