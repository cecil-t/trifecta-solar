<?php
declare(strict_types=1);

namespace App;

/**
 * The proposal's Clarifications and Terms & Conditions, kept as blocks so one edit fixes every
 * situation: each block has a kind, an order, a condition (when it prints) and body text with
 * placeholders. Saving the terms page makes a new version; the newest is current, and each
 * proposal names the version it printed.
 *
 * Body text: blank lines start paragraphs, lines starting "- " are bullets, **bold** is bold, and
 * {placeholders} fill in from the build sheet and the Rates page (PLACEHOLDERS).
 */
final class ProposalTerms
{
	public const KINDS = [
		'intro'         => 'Opening paragraph (before the Clarifications)',
		'clarification' => 'Clarification (one bullet)',
		'section'       => 'Terms & Conditions section',
		'authorization' => 'Final Authorizations and Consents',
		'addendum'      => 'Addendum (its own page and signature)',
	];

	public const CONDS = [
		'always'         => 'Always',
		'inhouse'        => 'In-house warranty only',
		'solarinsure'    => 'SolarInsure only',
		'guarantee'      => 'Production guarantee on',
		'battery'        => 'With a battery',
		'residential'    => 'Residential only',
		'nonresidential' => 'Commercial, nonprofit, government or agricultural only',
	];

	public const PLACEHOLDERS = [
		'company'               => 'Legal name (Rates page)',
		'hic'                   => 'PA HIC registration (Rates page)',
		'insurance'             => 'Insurance sentence (Rates page)',
		'customer'              => 'Customer name',
		'quote_number'          => 'Quote number',
		'deposit'               => 'Deposit amount',
		'price'                 => 'Total price',
		'guarantee_pct'         => 'Guarantee percent',
		'guarantee_years'       => 'Guarantee years',
		'guarantee_rate'        => 'Guarantee payout per kWh, like $0.15',
		'guarantee_kwh'         => 'Guaranteed kWh over the guarantee years',
		'guarantee_estimate_kwh' => 'PVWatts estimate over the guarantee years',
		'pvwatts_annual'        => 'PVWatts year-one kWh',
		'degradation'           => 'Module degradation, % a year',
	];

	public static function current(): ?array
	{
		return Db::one('SELECT v.*, u.name AS created_name FROM proposal_terms_versions v LEFT JOIN users u ON u.id = v.created_by ORDER BY v.id DESC LIMIT 1');
	}

	public static function versions(): array
	{
		return Db::all('SELECT v.*, u.name AS created_name, (SELECT COUNT(*) FROM proposal_terms_blocks b WHERE b.version_id = v.id) AS blocks
			FROM proposal_terms_versions v LEFT JOIN users u ON u.id = v.created_by ORDER BY v.id DESC');
	}

	public static function blocks(int $versionId): array
	{
		$order = "CASE kind WHEN 'intro' THEN 1 WHEN 'clarification' THEN 2 WHEN 'section' THEN 3 WHEN 'authorization' THEN 4 ELSE 5 END";
		return Db::all("SELECT * FROM proposal_terms_blocks WHERE version_id = ? ORDER BY $order, sort_order, id", [$versionId]);
	}

	/**
	 * Read the terms page's blocks. Each row: kind, cond, sort, heading, body, delete. Blank rows are
	 * skipped. Throws with a message on a problem.
	 */
	public static function fromInput(array $rows): array
	{
		$out = [];
		foreach ($rows as $r) {
			if (!is_array($r) || !empty($r['delete'])) {
				continue;
			}
			$heading = trim((string) ($r['heading'] ?? ''));
			$body = trim(str_replace("\r\n", "\n", (string) ($r['body'] ?? '')));
			if ($heading === '' && $body === '') {
				continue;
			}
			$kind = (string) ($r['kind'] ?? '');
			$cond = (string) ($r['cond'] ?? 'always');
			if (!isset(self::KINDS[$kind])) {
				throw new \InvalidArgumentException('Pick what each block is.');
			}
			if (!isset(self::CONDS[$cond])) {
				throw new \InvalidArgumentException('Pick when each block prints.');
			}
			if (mb_strlen($body) > 6000 || mb_strlen($heading) > 200) {
				throw new \InvalidArgumentException('A block is too long (6,000 characters at most).');
			}
			$out[] = ['kind' => $kind, 'cond' => $cond, 'sort_order' => (int) ($r['sort'] ?? 0), 'heading' => $heading !== '' ? $heading : null, 'body' => $body];
		}
		if (!$out) {
			throw new \InvalidArgumentException('The terms need at least one block.');
		}
		usort($out, static fn ($a, $b) => [$a['sort_order']] <=> [$b['sort_order']]);
		return $out;
	}

	/** Save blocks as a new version; returns its id. */
	public static function saveVersion(array $blocks, ?string $note): int
	{
		return Db::transaction(static fn (): int => self::insertVersion($blocks, $note));
	}

	/** The inserts for a new version (the caller holds the transaction). */
	private static function insertVersion(array $blocks, ?string $note): int
	{
		$note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;
		$vid = Db::insert('proposal_terms_versions', ['note' => $note, 'created_by' => Auth::id(), 'created_at' => now_utc()]);
		$i = 0;
		foreach ($blocks as $b) {
			Db::insert('proposal_terms_blocks', ['version_id' => $vid, 'kind' => $b['kind'], 'cond' => $b['cond'],
				'sort_order' => ++$i * 10, 'heading' => $b['heading'], 'body' => $b['body']]);
		}
		Activity::event('proposal_terms', $vid, 'Proposal terms saved as version ' . $vid . ($note ? ': ' . $note : '') . ' (' . count($blocks) . ' blocks)');
		return $vid;
	}

	/** Which conditions hold for a build sheet. */
	public static function facts(array $o, array $d, array $calc): array
	{
		$battery = false;
		foreach ($calc['lines'] ?? [] as $l) {
			$battery = $battery || (($l['category'] ?? '') === 'batteries' && ($l['qty_resolved'] ?? 0) > 0);
		}
		$residential = ($o['customer_type'] ?? 'residential') === 'residential' && empty($o['is_agricultural']);
		return [
			'always' => true,
			'inhouse' => empty($d['warranty_is_solarinsure']),
			'solarinsure' => !empty($d['warranty_is_solarinsure']),
			'guarantee' => !empty($d['guarantee_on']),
			'battery' => $battery,
			'residential' => $residential,
			'nonresidential' => !$residential,
		];
	}

	/**
	 * The current version's blocks that print for a build sheet, by kind, each with its heading and
	 * body as safe HTML.
	 *
	 * @return array{version: ?array, intro: list<array>, clarification: list<array>, section: list<array>, authorization: list<array>, addendum: list<array>, unknown: list<string>}
	 */
	public static function forProposal(array $facts, array $vars): array
	{
		$v = self::current();
		$out = ['version' => $v, 'unknown' => []] + array_fill_keys(array_keys(self::KINDS), []);
		if (!$v) {
			return $out;
		}
		foreach (self::blocks((int) $v['id']) as $b) {
			if (empty($facts[$b['cond']])) {
				continue;
			}
			$out[$b['kind']][] = [
				'heading' => $b['heading'] !== null ? self::inline((string) $b['heading'], $vars, $out['unknown']) : null,
				'html' => self::render((string) $b['body'], $vars, $out['unknown']),
			];
		}
		$out['unknown'] = array_values(array_unique($out['unknown']));
		return $out;
	}

	/** Body text to HTML: paragraphs, "- " bullets, **bold**, placeholders (escaped). */
	public static function render(string $body, array $vars, array &$unknown = []): string
	{
		$html = '';
		foreach (preg_split("/\n\s*\n/", trim($body)) as $para) {
			$lines = array_values(array_filter(array_map('rtrim', explode("\n", $para)), static fn ($l) => trim($l) !== ''));
			if (!$lines) {
				continue;
			}
			$bullets = array_filter($lines, static fn ($l) => str_starts_with(ltrim($l), '- '));
			if (count($bullets) === count($lines)) {
				$html .= '<ul>';
				foreach ($lines as $l) {
					$html .= '<li>' . self::inline(substr(ltrim($l), 2), $vars, $unknown) . '</li>';
				}
				$html .= '</ul>';
				continue;
			}
			$html .= '<p>' . implode('<br>', array_map(static fn ($l) => self::inline($l, $vars, $unknown), $lines)) . '</p>';
		}
		return $html;
	}

	/** One line: escape, then **bold** and {placeholders}. */
	public static function inline(string $text, array $vars, array &$unknown = []): string
	{
		$h = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
		return preg_replace_callback('/\{([a-z_0-9]+)\}/', static function ($m) use ($vars, &$unknown) {
			if (array_key_exists($m[1], $vars)) {
				return htmlspecialchars((string) $vars[$m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			}
			$unknown[] = $m[1];
			return $m[0];
		}, $h);
	}

	/**
	 * Add terms from a JSON file: {"note": "...", "blocks": [{kind, cond, heading, body}]}. Add-only:
	 * it makes a new version only when there are no terms yet, unless $newVersion. One transaction;
	 * a dry run rolls it back.
	 *
	 * @return array{counts: array, log: list<string>}
	 */
	public static function import(array $data, bool $dryRun, bool $newVersion = false): array
	{
		$log = [];
		$have = self::current();
		if ($have && !$newVersion) {
			return ['counts' => ['added' => 0, 'skipped' => 1], 'log' => ['skip   terms already exist (version ' . $have['id'] . '); use --new-version to add these as the next version']];
		}
		$rows = [];
		foreach ((array) ($data['blocks'] ?? []) as $i => $b) {
			$rows[] = ['kind' => $b['kind'] ?? '', 'cond' => $b['cond'] ?? 'always', 'heading' => $b['heading'] ?? '', 'body' => $b['body'] ?? '', 'sort' => $i];
		}
		$blocks = self::fromInput($rows);
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$vid = self::insertVersion($blocks, (string) ($data['note'] ?? 'Imported'));
			$dryRun ? $pdo->rollBack() : $pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		$counts = [];
		foreach ($blocks as $b) {
			$counts[$b['kind']] = ($counts[$b['kind']] ?? 0) + 1;
		}
		$log[] = 'add    version ' . ($dryRun ? '(not saved)' : $vid) . ': ' . implode(', ', array_map(static fn ($k, $n) => $n . ' ' . $k, array_keys($counts), $counts));
		return ['counts' => ['added' => count($blocks)] + $counts, 'log' => $log];
	}
}
