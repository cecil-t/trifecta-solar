<?php
declare(strict_types=1);

namespace App;

/**
 * Loads service.json from tools/import/build_service.py. Add-only by design: a ticket whose
 * number already exists is skipped, and existing customers are reused, never changed.
 */
final class ServiceImporter
{
    private array $log = [];
    private array $counts = ['tickets' => 0, 'skipped' => 0, 'visits' => 0, 'customers_added' => 0];

    public function __construct(private bool $dryRun = false)
    {
    }

    /** @return array{counts:array, log:string[]} */
    public function run(array $data): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($data['tickets'] ?? [] as $t) {
                $this->ticket($t);
            }
            $this->dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['counts' => $this->counts, 'log' => $this->log];
    }

    private function ticket(array $t): void
    {
        $label = $t['number'] . ' ' . $t['customer'];
        if (Db::value('SELECT id FROM service_tickets WHERE ticket_number = ?', [$t['number']])) {
            $this->counts['skipped']++;
            $this->log[] = "skip   $label (already exists)";
            return;
        }
        $customerId = Db::value("SELECT id FROM organizations WHERE type = 'customer' AND name = ? COLLATE NOCASE", [$t['customer']]);
        if (!$customerId) {
            $s = $t['site'] ?? [];
            $customerId = Db::insert('organizations', [
                'type' => 'customer', 'name' => $t['customer'],
                'street' => $s['street'] ?? null, 'city' => $s['city'] ?? null, 'state' => $s['state'] ?? null, 'zip' => $s['zip'] ?? null,
            ]);
            Activity::event('organization', (int) $customerId, 'Customer added by the Service Tracker import', null);
            $this->counts['customers_added']++;
        }
        $s = $t['site'] ?? [];
        $id = Db::insert('service_tickets', [
            'ticket_number' => $t['number'], 'opened_on' => $t['opened_on'], 'customer_id' => $customerId,
            'site_street' => $s['street'] ?? null, 'site_city' => $s['city'] ?? null, 'site_state' => $s['state'] ?? null, 'site_zip' => $s['zip'] ?? null,
            'description' => $t['description'], 'trifecta_install' => $t['trifecta_install'], 'coverage' => $t['coverage'],
            'completed_on' => $t['completed_on'], 'billing_note' => $t['billing_note'],
            'bill_amount_cents' => $t['amount'] !== null ? (int) round($t['amount'] * 100) : null,
            'invoiced' => $t['invoiced'] ? 1 : 0,
        ]);
        foreach ($t['visits'] as $v) {
            Db::insert('service_visits', [
                'ticket_id' => $id, 'visit_date' => $v['date'], 'crew' => $v['crew'], 'man_hours' => $v['hours'],
                'trips' => $v['trips'], 'note' => $v['note'],
            ]);
            $this->counts['visits']++;
        }
        Activity::event('service', $id, 'Imported from the Service Tracker spreadsheet' . ($t['notes'] ? ': ' . implode('; ', $t['notes']) : ''), null);
        $this->counts['tickets']++;
        $this->log[] = "import $label";
    }
}
