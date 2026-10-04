<?php
declare(strict_types=1);

namespace App;

/** Service tickets: numbering, lookups, derived status and visit totals. */
final class Service
{
	public const COVERAGE = ['billable' => 'Billable', 'warranty' => 'Warranty', 'solarinsure' => 'SolarInsure'];
	public const SOURCES = [
		'customer' => 'Customer request', 'monitoring' => 'Monitoring portal',
		'trifecta' => 'Found by Trifecta', 'other' => 'Other',
	];
	public const STATUS_LABELS = [
		'open' => 'Open', 'scheduled' => 'Scheduled', 'to_invoice' => 'Ready to invoice', 'done' => 'Completed',
	];

	/** Next S + YY + NNN number for the current year. */
	public static function nextNumber(): string
	{
		$yy = date('y');
		$max = Db::value(
			"SELECT MAX(CAST(SUBSTR(ticket_number, 4) AS INTEGER)) FROM service_tickets WHERE ticket_number GLOB ?",
			['S' . $yy . '[0-9][0-9][0-9]*']
		);
		return 'S' . $yy . str_pad((string) ((int) $max + 1), 3, '0', STR_PAD_LEFT);
	}

	/** Billable and SolarInsure work gets invoiced; warranty work does not. */
	public static function needsInvoice(array $t): bool
	{
		return in_array($t['coverage'], ['billable', 'solarinsure'], true);
	}

	/**
	 * open: still being worked; scheduled: a visit date is set and not completed;
	 * to_invoice: completed, needs an invoice, not invoiced yet; done: everything else completed.
	 */
	public static function status(array $t): string
	{
		if (empty($t['completed_on'])) {
			return !empty($t['scheduled_on']) ? 'scheduled' : 'open';
		}
		return self::needsInvoice($t) && !(int) $t['invoiced'] ? 'to_invoice' : 'done';
	}

	public static function find(int $id): ?array
	{
		return Db::one(
			"SELECT t.*, c.name AS customer_name, p.project_number, p.name AS project_name,
					u.name AS owner_name, u.initials AS owner_initials,
					(SELECT COALESCE(SUM(trips), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS trips,
					(SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours,
					(SELECT COUNT(*) FROM service_visits v WHERE v.ticket_id = t.id) AS visit_count
			 FROM service_tickets t
			 LEFT JOIN organizations c ON c.id = t.customer_id
			 LEFT JOIN projects p ON p.id = t.project_id
			 LEFT JOIN users u ON u.id = t.owner_id
			 WHERE t.id = ?",
			[$id]
		);
	}

	public static function visits(int $ticketId): array
	{
		return Db::all('SELECT * FROM service_visits WHERE ticket_id = ? ORDER BY visit_date IS NULL, visit_date, id', [$ticketId]);
	}

	public static function siteLine(array $t): string
	{
		return trim(implode(', ', array_filter([
			trim((string) ($t['site_street'] ?? '')), trim((string) ($t['site_city'] ?? '')),
			trim(($t['site_state'] ?? '') . ' ' . ($t['site_zip'] ?? '')),
		])));
	}

	public static function hours(?float $h): string
	{
		if ($h === null) {
			return '';
		}
		return rtrim(rtrim(number_format($h, 2), '0'), '.');
	}

	public static function projects(): array
	{
		return Db::all('SELECT p.id, p.project_number, p.name, p.customer_id FROM projects p ORDER BY p.project_number DESC');
	}
}
