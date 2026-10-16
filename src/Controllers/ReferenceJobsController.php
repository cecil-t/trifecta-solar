<?php
declare(strict_types=1);

namespace App\Controllers;

use App\ProductionRefs;
use App\Sam;
use App\SolarResource;
use App\View;

/**
 * Lists > Proposal APIs > Reference jobs (admin): built, monitored systems with their signed
 * proposal's estimate, run through SAM's detailed model and PVWatts v8 on NSRDB weather and compared
 * month by month with the proposal and with monitored production. Jobs are added by
 * import:reference-jobs; a run keeps its result on the job.
 */
final class ReferenceJobsController
{
	public function index(): void
	{
		$jobs = [];
		foreach (ProductionRefs::all() as $r) {
			$actual = ProductionRefs::actual($r);
			$jobs[] = $r + ['compare' => ProductionRefs::compare($r, $actual)];
		}
		View::render('reference/index', [
			'title' => 'Reference jobs',
			'jobs' => $jobs,
			'installed' => Sam::installed(),
			'weatherReady' => SolarResource::configured(),
		]);
	}

	public function run(int $id): void
	{
		$r = ProductionRefs::find($id);
		if (!$r) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			return;
		}
		set_time_limit(180);
		$reply = ProductionRefs::run($r);
		flash($reply['ok'] ? 'success' : 'error', $reply['ok'] ? 'Ran ' . $r['name'] . '.' : $r['name'] . ': ' . $reply['error']);
		redirect('/admin/reference-jobs#ref-' . $id);
	}

	public function runAll(): void
	{
		set_time_limit(600);
		$failed = [];
		foreach (ProductionRefs::all() as $r) {
			$reply = ProductionRefs::run($r);
			if (!$reply['ok']) {
				$failed[] = $r['name'] . ': ' . $reply['error'];
			}
		}
		flash($failed ? 'error' : 'success', $failed ? implode(' ', $failed) : 'Ran every job.');
		redirect('/admin/reference-jobs');
	}
}
