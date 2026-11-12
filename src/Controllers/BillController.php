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
		// Every bill read, so the salesperson picks the primary one when there are several
		$bills = array_map(static fn (array $b): array => [
			'fields' => $b['fields'], 'summary' => $b['summary'],
			'utility_text' => $b['raw']['utility'], 'supplier' => $b['raw']['supplier'], 'notes' => $b['raw']['notes'],
			'period' => trim($b['raw']['period_start'] . ' to ' . $b['raw']['period_end'], ' to'),
			'demand_kw' => $b['raw']['demand_kw'], 'uncertain' => $b['raw']['uncertain'],
		], $r['bills']);
		// The Notes text for the other bills, for each choice of primary bill
		$otherNotes = array_map(static fn (int $i): string => BillReader::otherBillsNote($r['bills'], $i), array_keys($r['bills']));
		echo json_encode(['ok' => true, 'bills' => $bills, 'other_notes' => $otherNotes, 'cost' => round((float) $r['cost'], 4)]);
	}
}
