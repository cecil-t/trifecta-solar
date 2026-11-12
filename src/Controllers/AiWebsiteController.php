<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\BillReader;
use App\Claude;
use App\Db;
use App\Presales;
use App\View;
use App\WebsiteRequests;

/**
 * Lists > Website and AI (admins): the website quote form connection (Gravity Forms) and the Claude
 * API that reads bills. Each check makes one call and saves nothing, except Check the website now,
 * which is a real poll, and the model choice.
 */
final class AiWebsiteController
{
	public function show(): void
	{
		$result = $_SESSION['ai_result'] ?? null;
		unset($_SESSION['ai_result']);
		View::render('admin/ai_website', [
			'title' => 'Website and AI',
			'gfSet' => WebsiteRequests::configured(),
			'formId' => WebsiteRequests::formId(),
			'lastPoll' => WebsiteRequests::lastRun(),
			'lastOk' => WebsiteRequests::lastRun(true),
			'counts' => array_column(Db::all('SELECT status, COUNT(*) AS n FROM web_requests GROUP BY status'), 'n', 'status'),
			'claudeSet' => Claude::configured(),
			'model' => Claude::model(),
			'usage30' => Claude::usage(30),
			'usageAll' => Claude::usage(36500),
			'heic' => $this->heicReady(),
			'result' => $result,
			'activity' => Activity::feed('ai_settings', 1),
		]);
	}

	/** One call each: the form's fields and its newest entries. */
	public function checkForm(): void
	{
		$form = WebsiteRequests::get('/forms/' . WebsiteRequests::formId());
		$out = ['kind' => 'form', 'ok' => $form['ok'], 'error' => $form['error'] ?? null];
		if ($form['ok']) {
			$out['title'] = (string) ($form['json']['title'] ?? '');
			$out['fields'] = [];
			foreach ((array) ($form['json']['fields'] ?? []) as $f) {
				$out['fields'][] = ['id' => (string) ($f['id'] ?? ''), 'label' => (string) ($f['label'] ?? ''), 'type' => (string) ($f['type'] ?? ''),
					'ours' => (string) (array_search((string) ($f['id'] ?? ''), WebsiteRequests::FIELDS, true) ?: '')];
			}
			$entries = WebsiteRequests::entries(1, 5);
			$out['entries_ok'] = $entries['ok'];
			$out['total'] = (int) ($entries['json']['total_count'] ?? 0);
			$out['newest'] = array_map(static function (array $e): array {
				$m = WebsiteRequests::map($e);
				return ['id' => (int) $e['id'], 'date' => (string) ($e['date_created'] ?? ''), 'name' => $m['name'], 'files' => count($m['files'])];
			}, (array) ($entries['json']['entries'] ?? []));
		}
		$_SESSION['ai_result'] = $out;
		redirect('/admin/ai-website');
	}

	/** A real poll, now, whatever the schedule says. */
	public function poll(): void
	{
		set_time_limit(600);
		$r = WebsiteRequests::poll(true);
		$_SESSION['ai_result'] = ['kind' => 'poll'] + $r;
		redirect('/admin/ai-website');
	}

	/** Read an uploaded bill and show what came back. Nothing is saved (the call is logged). */
	public function testBill(): void
	{
		set_time_limit(200);
		try {
			$files = BillReader::fromUpload('bill');
			$r = BillReader::read($files, 'bill_test');
		} catch (\InvalidArgumentException $e) {
			$r = ['ok' => false, 'error' => $e->getMessage()];
		}
		$_SESSION['ai_result'] = ['kind' => 'bill'] + $r;
		redirect('/admin/ai-website');
	}

	public function saveModel(): void
	{
		$m = (string) ($_POST['model'] ?? '');
		if (!isset(Claude::MODELS[$m])) {
			flash('error', 'Pick a model from the list.');
			redirect('/admin/ai-website');
		}
		$before = Claude::model();
		if ($m !== $before) {
			Db::run('INSERT OR REPLACE INTO presales_settings (key, value, updated_at) VALUES (?, ?, ?)', ['ai_model', $m, now_utc()]);
			Presales::forgetSettings();
			Activity::event('ai_settings', 1, 'Claude model: ' . Claude::MODELS[$before][0] . ' to ' . Claude::MODELS[$m][0]);
		}
		flash('success', 'Saved.');
		redirect('/admin/ai-website');
	}

	/** Whether photo conversion is installed: the WeasyPrint venv with pillow-heif. */
	private function heicReady(): ?bool
	{
		$py = \App\Pdf::python();
		if (!is_executable($py)) {
			return null;
		}
		return (bool) glob(dirname($py, 2) . '/lib/python3*/site-packages/pillow_heif');
	}
}
