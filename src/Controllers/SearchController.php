<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Service;
use App\Tasks;
use App\View;

/**
 * The search box in the top bar: projects and service tickets in one list, matched on the same
 * fields as the Projects and Service list searches. A single match opens it directly.
 */
final class SearchController
{
	public function index(): void
	{
		$q = trim((string) ($_GET['q'] ?? ''));
		$projects = $tickets = [];
		if ($q !== '') {
			$like = '%' . $q . '%';
			$projects = Db::all(
				'SELECT p.*, u.initials AS sales_initials, u.name AS sales_name, m.name AS municipality_name, c.name AS customer_name
				 FROM projects p
				 LEFT JOIN users u ON u.id = p.salesperson_id
				 LEFT JOIN municipalities m ON m.id = p.municipality_id
				 LEFT JOIN organizations c ON c.id = p.customer_id
				 WHERE p.name LIKE ? OR p.project_number LIKE ? OR c.name LIKE ? OR m.name LIKE ?
				 ORDER BY p.project_number DESC',
				array_fill(0, 4, $like)
			);
			$tasks = Tasks::forProjects(array_column($projects, 'id'));
			foreach ($projects as &$p) {
				$p['status'] = Tasks::status($p, $tasks[(int) $p['id']] ?? []);
			}
			unset($p);

			$tickets = Db::all(
				'SELECT t.*, c.name AS customer_name, p.project_number
				 FROM service_tickets t
				 LEFT JOIN organizations c ON c.id = t.customer_id
				 LEFT JOIN projects p ON p.id = t.project_id
				 WHERE t.ticket_number LIKE ? OR c.name LIKE ? OR t.description LIKE ? OR t.site_city LIKE ? OR t.site_street LIKE ?
				 ORDER BY t.ticket_number DESC',
				array_fill(0, 5, $like)
			);
			foreach ($tickets as &$t) {
				$t['status'] = Service::status($t);
			}
			unset($t);

			if (count($projects) + count($tickets) === 1) {
				// No results page to go back to, so the back link goes to the plain list instead of an older search.
				$_SESSION[$projects ? 'projects_list' : 'service_list'] = $projects ? '/projects' : '/service';
				redirect($projects ? '/projects/' . (int) $projects[0]['id'] : '/service/' . (int) $tickets[0]['id']);
			}
			// The back link on a project or ticket returns to these results.
			$here = '/search?' . http_build_query(['q' => $q]);
			$_SESSION['projects_list'] = $here;
			$_SESSION['service_list'] = $here;
		}

		View::render('search', ['title' => $q !== '' ? 'Search: ' . $q : 'Search', 'q' => $q, 'projects' => $projects, 'tickets' => $tickets]);
	}
}
