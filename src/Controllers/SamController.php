<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Sam;
use App\View;

/**
 * Lists > SAM (admin): checks that SAM (System Advisor Model) is installed on the server and runs.
 * A test bench for the production estimates the proposal system will need; nothing is saved.
 */
final class SamController
{
	public function test(): void
	{
		$this->render(null);
	}

	public function run(): void
	{
		$result = Sam::call(['action' => 'check']);
		$result['at'] = date('m/d/Y g:i:s A');
		$this->render($result);
	}

	private function render(?array $result): void
	{
		View::render('sam/test', [
			'title' => 'SAM',
			'installed' => Sam::installed(),
			'python' => Sam::python(),
			'libFolder' => Sam::libFolder(),
			'result' => $result,
		]);
	}
}
