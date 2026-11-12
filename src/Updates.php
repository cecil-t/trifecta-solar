<?php
declare(strict_types=1);

namespace App;

/**
 * Newer versions of what the app is built on. updates:check (cron, daily; it works once a week, or
 * any time with --force or About > Check for updates) compares what the app uses with the newest
 * available and keeps one row per item in update_checks. Admins see a red dot on their avatar and on
 * About in its menu, and a card at the top of About, until the item is upgraded or hidden.
 *
 * Nothing is upgraded here: each item says what upgrading it involves, and someone decides when.
 */
final class Updates
{
	public const EVERY_DAYS = 7;

	/** A central Pennsylvania point for asking NSRDB which typical-year datasets exist. */
	private const NSRDB_POINT = [40.2, -76.4];

	/** item => [label, what upgrading involves] */
	public const ITEMS = [
		'sam' => ['SAM (System Advisor Model)', 'Production and bill models change between SAM releases, so proposal numbers can move. Claude updates SAM_LIBRARY_TAG and the two library hashes in the Dockerfile; you rebuild (trifecta-update); then run About > Proposal APIs > Run check and Reference jobs > Run all and compare with before.'],
		'pysam' => ['PySAM', 'The Python wrapper for SAM, usually upgraded together with SAM. Claude updates PYSAM_VERSION in the Dockerfile; you rebuild; then run Proposal APIs > Run check and Reference jobs.'],
		'weasyprint' => ['WeasyPrint', 'Makes the proposal PDFs. Claude updates WEASYPRINT_VERSION in the Dockerfile; you rebuild; then Proposal APIs > Test PDF and open a proposal PDF to check the layout.'],
		'pillow_heif' => ['pillow-heif', 'Turns iPhone HEIC photos into JPEG for the bill reader. Claude updates PILLOW_HEIF_VERSION in the Dockerfile; you rebuild; then Website and AI > Test a bill with an iPhone photo.'],
		'nsrdb' => ['NSRDB typical-year weather', 'A newer typical year moves production estimates a little. Claude changes the dataset in the code (no rebuild); new weather files download as sites are run; then run Reference jobs and compare with before.'],
		'claude' => ['Claude model', 'Pick the model on About > Website and AI, then Test a bill with a few real bills and compare with the old model. A retired model stops bill reading until another is picked.'],
		'php' => ['PHP', 'Security fixes come with a rebuild that pulls the newest php:8.3-apache image (docker compose build --pull). Moving to a newer PHP branch before 8.3 reaches end of life is a Dockerfile change Claude makes and tests first.'],
	];

	// ------------------------------------------------------------------ reading

	/** Every checked item with its label and how-to, in ITEMS order. */
	public static function all(): array
	{
		$rows = [];
		try {
			foreach (Db::all('SELECT * FROM update_checks') as $r) {
				$rows[$r['item']] = $r;
			}
		} catch (\Throwable) {
			return [];
		}
		$out = [];
		foreach (self::ITEMS as $key => [$label, $how]) {
			if (isset($rows[$key])) {
				$out[$key] = $rows[$key] + ['label' => $label, 'how' => $how, 'flagged' => self::flagged($rows[$key])];
			}
		}
		return $out;
	}

	/** Items that need attention: newer version or an alert, not hidden for that version. */
	public static function flagged(array $r): bool
	{
		return in_array($r['level'], ['minor', 'major', 'alert'], true)
			&& ($r['dismissed_version'] === null || $r['dismissed_version'] !== $r['latest_version']);
	}

	/** How many items are flagged (for the red dots), 0 when the table is not there yet. */
	public static function count(): int
	{
		static $n = null;
		if ($n === null) {
			try {
				$n = (int) Db::value("SELECT COUNT(*) FROM update_checks WHERE level IN ('minor','major','alert')
					AND (dismissed_version IS NULL OR dismissed_version <> COALESCE(latest_version, ''))");
			} catch (\Throwable) {
				$n = 0;
			}
		}
		return $n;
	}

	public static function lastChecked(): ?string
	{
		try {
			$v = Db::value('SELECT MAX(checked_at) FROM update_checks');
			return $v ? (string) $v : null;
		} catch (\Throwable) {
			return null;
		}
	}

	public static function due(): bool
	{
		$last = self::lastChecked();
		return $last === null || strtotime($last) < time() - self::EVERY_DAYS * 86400 + 3600;
	}

	/** Hide an item's flag until a newer version than the one found now. */
	public static function dismiss(string $item): void
	{
		Db::run('UPDATE update_checks SET dismissed_version = latest_version WHERE item = ?', [$item]);
	}

	// ------------------------------------------------------------------ checking

	/** Check every item now. Returns one line per item. */
	public static function run(): array
	{
		$lines = [];
		foreach (array_keys(self::ITEMS) as $key) {
			try {
				$r = match ($key) {
					'sam' => self::sam(),
					'pysam' => self::pypi('nlr-pysam', self::pin('PYSAM_VERSION'), 'https://github.com/NatLabRockies/pysam/releases'),
					'weasyprint' => self::pypi('weasyprint', self::pin('WEASYPRINT_VERSION'), 'https://doc.courtbouillon.org/weasyprint/stable/changelog.html'),
					'pillow_heif' => self::pypi('pillow-heif', self::pin('PILLOW_HEIF_VERSION'), 'https://github.com/bigcat88/pillow_heif/releases'),
					'nsrdb' => self::nsrdb(),
					'claude' => self::claude(),
					'php' => self::php(),
				};
			} catch (\Throwable $e) {
				$r = ['current' => null, 'latest' => null, 'level' => 'error', 'note' => 'Check failed: ' . $e->getMessage(), 'url' => null];
			}
			if ($r === null) {
				continue; // not set up here (no key), nothing to check
			}
			$before = Db::one('SELECT dismissed_version FROM update_checks WHERE item = ?', [$key]);
			Db::run('INSERT OR REPLACE INTO update_checks (item, current_version, latest_version, level, note, url, dismissed_version, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
				$key, $r['current'], $r['latest'], $r['level'], $r['note'] !== null ? mb_substr($r['note'], 0, 500) : null, $r['url'],
				$before['dismissed_version'] ?? null, now_utc(),
			]);
			$lines[] = self::ITEMS[$key][0] . ': ' . ($r['level'] === 'ok' ? 'up to date (' . $r['current'] . ')' : ($r['note'] ?? $r['level']));
		}
		return $lines;
	}

	/** A version pinned in the Dockerfile (ARG NAME=value), or null. */
	public static function pin(string $arg): ?string
	{
		$file = @file_get_contents(APP_ROOT . '/Dockerfile');
		return $file !== false && preg_match('/^ARG ' . preg_quote($arg, '/') . '=(\S+)/m', $file, $m) ? $m[1] : null;
	}

	/** major when the first number changes, minor otherwise, ok when not newer. */
	private static function level(string $current, string $latest): string
	{
		if (version_compare($latest, $current, '<=')) {
			return 'ok';
		}
		return (int) $current !== (int) $latest ? 'major' : 'minor';
	}

	private static function json(string $url, array $headers = []): array
	{
		$r = Http::request('GET', $url, $headers, '', 30);
		if ($r['status'] !== 200 || !is_array($r['json'])) {
			throw new \RuntimeException(($r['status'] ? 'answered ' . $r['status'] : ($r['error'] ?? 'no answer')) . ' from ' . (parse_url($url, PHP_URL_HOST) ?: $url));
		}
		return $r['json'];
	}

	/** SAM: the newest release tag like 2026.7.3.r0.ssc.308 on GitHub. */
	private static function sam(): array
	{
		$current = self::pin('SAM_LIBRARY_TAG') ?? '';
		$best = null;
		foreach (self::json('https://api.github.com/repos/NatLabRockies/SAM/tags?per_page=100', ['Accept: application/vnd.github+json']) as $t) {
			$name = (string) ($t['name'] ?? '');
			if (ThirdParty::samRelease($name) !== null && ($best === null || self::samKey($name) > self::samKey($best))) {
				$best = $name;
			}
		}
		if ($best === null) {
			throw new \RuntimeException('no release tags found on GitHub');
		}
		$cur = ThirdParty::samRelease($current);
		$new = ThirdParty::samRelease($best);
		$level = self::samKey($best) > self::samKey($current) ? (substr($best, 0, 4) !== substr($current, 0, 4) ? 'major' : 'minor') : 'ok';
		return ['current' => $cur['release'] ?? $current, 'latest' => $new['release'] ?? $best, 'level' => $level,
			'note' => $level === 'ok' ? null : 'SAM ' . $new['release'] . ' is out (released ' . $new['date'] . '); this server has ' . ($cur['release'] ?? $current) . '.',
			'url' => 'https://github.com/NatLabRockies/SAM/releases'];
	}

	/** Sortable key for a SAM tag: year, month, day, revision, core build. */
	private static function samKey(string $tag): string
	{
		return preg_match('/^(\d{4})\.(\d{1,2})\.(\d{1,2})\.r(\d+)\.ssc\.(\d+)$/', $tag, $m)
			? sprintf('%04d%02d%02d%03d%05d', $m[1], $m[2], $m[3], $m[4], $m[5]) : '0';
	}

	private static function pypi(string $package, ?string $current, string $url): array
	{
		if ($current === null) {
			throw new \RuntimeException('no version pinned in the Dockerfile');
		}
		$latest = (string) (self::json('https://pypi.org/pypi/' . $package . '/json')['info']['version'] ?? '');
		if ($latest === '') {
			throw new \RuntimeException('PyPI gave no version');
		}
		$level = self::level($current, $latest);
		return ['current' => $current, 'latest' => $latest, 'level' => $level,
			'note' => $level === 'ok' ? null : $package . ' ' . $latest . ' is out' . ($level === 'major' ? ' (a major release)' : '') . '; this server has ' . $current . '.',
			'url' => $url];
	}

	/** NSRDB: the newest typical-year dataset (tmy-YYYY) NSRDB lists for a central PA point. */
	private static function nsrdb(): ?array
	{
		if (!SolarResource::configured()) {
			return null;
		}
		$current = SolarResource::DATASET;
		[$lat, $lon] = self::NSRDB_POINT;
		$j = self::json('https://developer.nlr.gov/api/solar/nsrdb_data_query.json?' . http_build_query(['api_key' => Config::get('NLR_API_KEY'), 'lat' => $lat, 'lon' => $lon]));
		$years = [];
		foreach ((array) ($j['outputs'] ?? []) as $o) {
			$docs = strtolower((string) ($o['apiDocs'] ?? '') . ' ' . ($o['name'] ?? ''));
			if (!str_contains($docs, 'tmy')) {
				continue;
			}
			foreach ((array) ($o['availableYears'] ?? []) as $y) {
				if (preg_match('/^tmy-(\d{4})$/i', (string) $y, $m)) {
					$years[] = (int) $m[1];
				}
			}
		}
		if (!$years) {
			throw new \RuntimeException('NSRDB\'s dataset list had no typical-year names to compare (tmy-YYYY)');
		}
		$latest = 'tmy-' . max($years);
		$level = max($years) > (int) substr($current, 4) ? 'major' : 'ok';
		return ['current' => $current, 'latest' => $latest, 'level' => $level,
			'note' => $level === 'ok' ? null : 'NSRDB has ' . $latest . '; production estimates use ' . $current . '.',
			'url' => 'https://nsrdb.nlr.gov/data-sets/tmy'];
	}

	/** Claude: is the model in use still offered, and is there a newer one of the same kind. */
	private static function claude(): ?array
	{
		if (!Claude::configured()) {
			return null;
		}
		$model = Claude::model();
		$j = self::json('https://api.anthropic.com/v1/models?limit=100', ['Authorization: Bearer ' . Config::get('ANTHROPIC_API_KEY'), 'anthropic-version: ' . Claude::API_VERSION]);
		$list = (array) ($j['data'] ?? []);
		$mine = null;
		foreach ($list as $m) {
			if (($m['id'] ?? '') === $model) {
				$mine = $m;
			}
		}
		$label = Claude::MODELS[$model][0] ?? $model;
		if ($mine === null) {
			return ['current' => $label, 'latest' => null, 'level' => 'alert',
				'note' => $label . ' is no longer offered, so bill reading will fail. Pick another model on Website and AI.', 'url' => 'https://platform.claude.com/docs/en/about-claude/model-deprecations'];
		}
		$family = preg_replace('/^claude-([a-z]+)-.*$/', '$1', $model);
		$newer = array_filter($list, static fn ($m) => preg_replace('/^claude-([a-z]+)-.*$/', '$1', (string) ($m['id'] ?? '')) === $family
			&& strtotime((string) ($m['created_at'] ?? '')) > strtotime((string) ($mine['created_at'] ?? '')));
		if (!$newer) {
			return ['current' => $label, 'latest' => $label, 'level' => 'ok', 'note' => null, 'url' => null];
		}
		usort($newer, static fn ($a, $b) => strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']));
		$name = (string) ($newer[0]['display_name'] ?? $newer[0]['id']);
		$known = isset(Claude::MODELS[(string) $newer[0]['id']]);
		return ['current' => $label, 'latest' => $name, 'level' => 'minor',
			'note' => $name . ' is out; bill reading uses ' . $label . '.' . ($known ? '' : ' It is not in the model list yet, so Claude adds it (with its price) first.'),
			'url' => 'https://platform.claude.com/docs/en/about-claude/models/overview'];
	}

	/** PHP: end of life of this branch, and whether a newer patch is out (endoflife.date). */
	private static function php(): array
	{
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$row = null;
		foreach (self::json('https://endoflife.date/api/php.json') as $c) {
			if ((string) ($c['cycle'] ?? '') === $branch) {
				$row = $c;
			}
		}
		if ($row === null) {
			throw new \RuntimeException('endoflife.date does not list PHP ' . $branch);
		}
		$eol = is_string($row['eol'] ?? null) ? strtotime($row['eol']) : null;
		$latest = (string) ($row['latest'] ?? PHP_VERSION);
		$url = 'https://www.php.net/supported-versions.php';
		if ($eol !== null && $eol < time()) {
			return ['current' => PHP_VERSION, 'latest' => $latest, 'level' => 'alert', 'note' => 'PHP ' . $branch . ' stopped getting security fixes on ' . date('m/d/Y', $eol) . '. Move to a newer PHP.', 'url' => $url];
		}
		if ($eol !== null && $eol < time() + 180 * 86400) {
			return ['current' => PHP_VERSION, 'latest' => $latest, 'level' => 'major', 'note' => 'PHP ' . $branch . ' stops getting security fixes on ' . date('m/d/Y', $eol) . '. Plan the move to a newer PHP.', 'url' => $url];
		}
		if (version_compare($latest, PHP_VERSION, '>')) {
			return ['current' => PHP_VERSION, 'latest' => $latest, 'level' => 'minor', 'note' => 'PHP ' . $latest . ' is out; this server has ' . PHP_VERSION . '. A rebuild with --pull picks it up.', 'url' => $url];
		}
		return ['current' => PHP_VERSION, 'latest' => $latest, 'level' => 'ok', 'note' => null, 'url' => $url];
	}
}
