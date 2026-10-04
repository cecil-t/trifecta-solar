<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Municipalities;
use App\Projects;
use App\View;

/** Anyone can add a municipality (name + county, unique per county); admins set its usual providers. */
final class MunicipalityController
{
	public function index(): void
	{
		$rows = Db::all(
			"SELECT m.*, c.name AS county, c.state_code,
					(SELECT COUNT(*) FROM projects p WHERE p.municipality_id = m.id) AS project_count
			 FROM municipalities m JOIN counties c ON c.id = m.county_id
			 ORDER BY c.state_code = 'PA' DESC, c.name, m.name"
		);
		View::render('municipalities/index', ['title' => 'Municipalities', 'rows' => $rows, 'counties' => Projects::counties()]);
	}

	public function store(): void
	{
		try {
			$m = Municipalities::findOrCreate((string) ($_POST['name'] ?? ''), (int) ($_POST['county_id'] ?? 0));
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage());
			redirect('/municipalities');
		}
		flash($m['created'] ? 'success' : 'info', $m['created'] ? $m['name'] . ' added.' : $m['name'] . ' already exists in that county.');
		redirect('/municipalities/' . $m['id']);
	}

	public function show(int $id): void
	{
		$m = Db::one('SELECT m.*, c.name AS county, c.state_code FROM municipalities m JOIN counties c ON c.id = m.county_id WHERE m.id = ?', [$id]);
		if (!$m) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			return;
		}
		View::render('municipalities/show', [
			'title' => $m['name'], 'm' => $m,
			'agencies' => Projects::orgs('agency'),
			'contacts' => Db::all("SELECT * FROM contacts WHERE owner_type = 'municipality' AND owner_id = ? ORDER BY is_active DESC, is_primary DESC, name", [$id]),
			'projects' => Db::all('SELECT id, project_number, name FROM projects WHERE municipality_id = ? ORDER BY project_number', [$id]),
			'activity' => Activity::feed('municipality', $id),
			'counties' => Projects::counties(),
		]);
	}

	public function update(int $id): void
	{
		if (!Auth::isAdmin()) {
			flash('error', 'Only admins can change municipality settings.');
			redirect('/municipalities/' . $id);
		}
		$before = Db::one('SELECT * FROM municipalities WHERE id = ?', [$id]) ?? redirect('/municipalities');
		$name = trim((string) ($_POST['name'] ?? ''));
		$countyId = (int) ($_POST['county_id'] ?? 0);
		$key = Municipalities::nameKey($name);
		if ($key === '' || !Db::value('SELECT 1 FROM counties WHERE id = ?', [$countyId])) {
			flash('error', 'Name and county are required.');
			redirect('/municipalities/' . $id);
		}
		if (Db::value('SELECT id FROM municipalities WHERE name_key = ? AND county_id = ? AND id <> ?', [$key, $countyId, $id])) {
			flash('error', 'That municipality already exists in that county.');
			redirect('/municipalities/' . $id);
		}
		$data = ['name' => Municipalities::displayName($name), 'name_key' => $key, 'county_id' => $countyId,
			'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null];
		$data += Municipalities::providerInput();
		Db::update('municipalities', $id, $data + ['updated_at' => now_utc()]);
		Activity::changes('municipality', $id, $before, $data, ['name' => 'Name', 'county_id' => 'County', 'notes' => 'Notes'] + Municipalities::providerLabels(),
			['county_id' => static fn ($v) => (string) Db::value("SELECT name || ' Co., ' || state_code FROM counties WHERE id = ?", [$v])] + Municipalities::providerFormatters());
		flash('success', 'Saved. New projects in ' . $data['name'] . ' will start with these providers.');
		redirect('/municipalities/' . $id);
	}
}
