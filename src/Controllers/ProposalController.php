<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Pdf;
use App\Presales;
use App\Proposal;
use App\ProposalTerms;
use App\View;

/** A build sheet's customer proposal (page and PDF), and the proposal terms page. */
final class ProposalController
{
	/** The proposal in the browser, with what to fix before sending. */
	public function show(int $id, int $designId): void
	{
		[$o, $d] = $this->find($id, $designId);
		$data = Proposal::data($o, $d);
		header('X-Robots-Tag: noindex, nofollow');
		echo View::partial('proposal/document', $data + ['assets' => '/', 'screen' => true, 'base' => '/presales/' . $id . '/designs/' . $designId]);
	}

	/** The proposal as a PDF (WeasyPrint). */
	public function pdf(int $id, int $designId): void
	{
		[$o, $d] = $this->find($id, $designId);
		$data = Proposal::data($o, $d);
		set_time_limit(120);
		$r = Pdf::fromHtml(View::partial('proposal/document', $data + ['assets' => '', 'screen' => false, 'base' => '']), 90);
		if (!$r['ok']) {
			flash('error', 'The PDF could not be made: ' . $r['error']);
			redirect('/presales/' . $id . '/designs/' . $designId . '/proposal');
		}
		Activity::event('presales', $id, 'Proposal PDF for "' . $d['name'] . '" (quote ' . $data['quote'] . ')' . ($data['terms']['version'] ? ', terms version ' . $data['terms']['version']['id'] : ''));
		$name = preg_replace('/[^A-Za-z0-9 ._-]+/', '', 'Trifecta Solar Proposal ' . $data['quote'] . ' - ' . $data['customerName']) ?: 'Proposal';
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
		header('Content-Length: ' . strlen($r['pdf']));
		header('X-Robots-Tag: noindex, nofollow');
		echo $r['pdf'];
	}

	/** Lists > Proposal terms: anyone reads them (like Sales rates); admins edit. */
	public function terms(): void
	{
		$v = ProposalTerms::current();
		$old = $_SESSION['old_terms'] ?? null;
		unset($_SESSION['old_terms']);
		View::render('presales/terms', [
			'title' => 'Proposal terms',
			'version' => $v,
			'blocks' => $old['blocks'] ?? ($v ? ProposalTerms::blocks((int) $v['id']) : []),
			'note' => $old['note'] ?? '',
			'versions' => ProposalTerms::versions(),
			'canEdit' => Auth::isAdmin(),
		]);
	}

	public function saveTerms(): void
	{
		if (!Auth::isAdmin()) {
			View::render('errors/403', ['title' => 'Not allowed'], 'layout', 403);
			return;
		}
		$rows = (array) ($_POST['blocks'] ?? []);
		try {
			$blocks = ProposalTerms::fromInput($rows);
			$cur = ProposalTerms::current();
			if ($cur && self::same($blocks, ProposalTerms::blocks((int) $cur['id']))) {
				flash('success', 'No changes.');
				redirect('/presales/terms');
			}
			$vid = ProposalTerms::saveVersion($blocks, (string) ($_POST['note'] ?? ''));
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_terms'] = ['blocks' => array_values(array_map(static fn ($r) => [
				'kind' => $r['kind'] ?? '', 'cond' => $r['cond'] ?? 'always', 'sort_order' => (int) ($r['sort'] ?? 0),
				'heading' => $r['heading'] ?? '', 'body' => $r['body'] ?? ''], array_filter($rows, 'is_array'))), 'note' => $_POST['note'] ?? ''];
			flash('error', $e->getMessage() . ' Nothing was saved.');
			redirect('/presales/terms');
		}
		flash('success', 'Saved as version ' . $vid . '. Proposals use it from now on.');
		redirect('/presales/terms');
	}

	/** The same blocks in the same order. */
	private static function same(array $a, array $b): bool
	{
		$key = static fn (array $x) => array_map(static fn ($r) => [$r['kind'], $r['cond'], (string) $r['heading'], (string) $r['body']], $x);
		return $key($a) === $key(array_values($b));
	}

	private function find(int $oppId, int $id): array
	{
		Presales::requireUse();
		$o = Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$oppId]);
		$d = $o ? Db::one('SELECT * FROM presales_designs WHERE id = ? AND opportunity_id = ?', [$id, $oppId]) : null;
		if (!$o || !$d || !Presales::canSee($o)) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return [$o, $d];
	}
}
