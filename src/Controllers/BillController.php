<?php
declare(strict_types=1);

namespace App\Controllers;

use App\BillReader;
use App\Claude;
use App\Presales;

/** Read a bill on the opportunity form: the uploaded files go to Claude, the fields come back as JSON. Nothing is saved. */
final class BillController
{
	public function read(): void
	{
		Presales::requireUse();
		header('Content-Type: application/json');
		set_time_limit(200);
		if (!Claude::configured()) {
			echo json_encode(['ok' => false, 'error' => 'Bill reading is not set up yet (the Claude API key is missing on the server).']);
			return;
		}
		try {
			$files = BillReader::fromUpload('bill');
		} catch (\InvalidArgumentException $e) {
			echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
			return;
		}
		if (!$files && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
			echo json_encode(['ok' => false, 'error' => 'The files are too large together. Send fewer or smaller files.']);
			return;
		}
		$r = BillReader::read($files);
		if (!$r['ok']) {
			echo json_encode(['ok' => false, 'error' => $r['error']]);
			return;
		}
		echo json_encode([
			'ok' => true, 'fields' => $r['fields'],
			'utility_text' => $r['raw']['utility'], 'supplier' => $r['raw']['supplier'], 'notes' => $r['raw']['notes'],
			'period' => trim($r['raw']['period_start'] . ' to ' . $r['raw']['period_end'], ' to'),
			'demand_kw' => $r['raw']['demand_kw'], 'uncertain' => $r['raw']['uncertain'],
			'cost' => round((float) $r['cost'], 4),
		]);
	}
}
