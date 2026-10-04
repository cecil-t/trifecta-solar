<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;

/** Edit / delete your own comments (within 7 days) on any record's log. */
final class ActivityController
{
	public function edit(int $id): void
	{
		$ok = Activity::editComment($id, (string) ($_POST['body'] ?? ''));
		flash($ok ? 'success' : 'error', $ok ? 'Comment updated.' : 'That comment can no longer be edited.');
		redirect($this->back());
	}

	public function delete(int $id): void
	{
		$ok = Activity::deleteComment($id);
		flash($ok ? 'success' : 'error', $ok ? 'Comment deleted.' : 'That comment can no longer be deleted.');
		redirect($this->back());
	}

	private function back(): string
	{
		return local_path((string) ($_POST['back'] ?? '/'));
	}
}
