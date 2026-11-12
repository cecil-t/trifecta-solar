<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Claude;
use App\Db;
use App\Presales;
use App\View;
use App\WebsiteRequests;

/**
 * A website quote request: what the person sent, what the bill reader found, and existing customers
 * it may belong to. Create opportunity opens the New opportunity form filled from it; Dismiss is for
 * spam and tests. Anyone who can use Sales sees requests (they have no salesperson yet).
 */
final class WebRequestController
{
	public function show(int $id): void
	{
		Presales::requireUse();
		$req = $this->find($id);
		$opp = $req['opportunity_id'] ? Db::one('SELECT id, name FROM presales_opportunities WHERE id = ?', [(int) $req['opportunity_id']]) : null;
		View::render('presales/request', [
			'title' => 'Website request: ' . (($req['data']['name'] ?? '') ?: '#' . $req['entry_id']),
			'req' => $req, 'matches' => WebsiteRequests::matches($req), 'opp' => $opp,
			'handledBy' => $req['handled_by'] ? Db::value('SELECT name FROM users WHERE id = ?', [(int) $req['handled_by']]) : null,
			'canRead' => Claude::configured(),
			'activity' => Activity::feed('web_request', $id),
		]);
	}

	public function dismiss(int $id): void
	{
		Presales::requireUse();
		$req = $this->find($id);
		if ($req['status'] === 'pending') {
			Db::update('web_requests', $id, ['status' => 'dismissed', 'handled_by' => Auth::id(), 'handled_at' => now_utc()]);
			$why = trim((string) ($_POST['reason'] ?? ''));
			Activity::event('web_request', $id, 'Dismissed' . ($why !== '' ? ': ' . mb_substr($why, 0, 200) : ''));
			flash('success', 'Request dismissed.');
		}
		redirect('/presales');
	}

	public function reopen(int $id): void
	{
		Presales::requireUse();
		$req = $this->find($id);
		if ($req['status'] === 'dismissed') {
			Db::update('web_requests', $id, ['status' => 'pending', 'handled_by' => null, 'handled_at' => null]);
			Activity::event('web_request', $id, 'Back to Pending');
			flash('success', 'Back on the Pending list.');
		}
		redirect('/presales/requests/' . $id);
	}

	/** Read (or read again) the attached bill. */
	public function read(int $id): void
	{
		Presales::requireUse();
		$this->find($id);
		set_time_limit(300);
		$r = WebsiteRequests::readBill($id);
		if ($r['ok']) {
			Activity::event('web_request', $id, 'Bill read');
		}
		flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Bill read.' : 'The bill could not be read: ' . $r['error']);
		redirect('/presales/requests/' . $id);
	}

	/** One attached bill file, fetched from the website and passed through (nothing kept here). */
	public function file(int $id, int $n): void
	{
		Presales::requireUse();
		$req = $this->find($id);
		$url = $req['files'][$n - 1] ?? null;
		if ($url === null) {
			$this->notFound();
		}
		$d = WebsiteRequests::download((string) $url);
		if (!$d['ok']) {
			flash('error', 'The bill file could not be opened: ' . $d['error']);
			redirect('/presales/requests/' . $id);
		}
		$type = match (\App\BillReader::kind($d['bytes'])) {
			'pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
			default => 'application/octet-stream',
		};
		$name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) parse_url((string) $url, PHP_URL_PATH))) ?: 'bill';
		header('Content-Type: ' . $type);
		header('Content-Disposition: ' . ($type === 'image/heic' || $type === 'application/octet-stream' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		echo $d['bytes'];
	}

	private function find(int $id): array
	{
		return WebsiteRequests::find($id) ?? $this->notFound();
	}

	private function notFound(): never
	{
		View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
		exit;
	}
}
