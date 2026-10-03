<?php
declare(strict_types=1);

namespace App;

final class Municipalities
{
    /** Who handles each review: options and the columns they live in (projects and municipalities). */
    public const BY = ['' => 'Not set', 'municipality' => 'Municipality', 'county' => 'County', 'third_party' => 'Third party'];
    public const SLOTS = [
        'zoning'     => ['label' => 'Zoning',          'by' => 'zoning_by',     'org' => 'zoning_org_id'],
        'building'   => ['label' => 'Building permit', 'by' => 'building_by',   'org' => 'building_org_id'],
        'inspection' => ['label' => 'Inspections',     'by' => 'inspection_by', 'org' => 'inspection_org_id'],
    ];

    /** Read the three provider slots from a POSTed form. */
    public static function providerInput(): array
    {
        $data = [];
        foreach (self::SLOTS as $slot) {
            $by = $_POST[$slot['by']] ?? '';
            $by = isset(self::BY[$by]) && $by !== '' ? $by : null;
            $data[$slot['by']] = $by;
            $data[$slot['org']] = $by === 'third_party' ? (((int) ($_POST[$slot['org']] ?? 0)) ?: null) : null;
        }
        return $data;
    }

    /** Labels and value formatters for the activity log. */
    public static function providerLabels(): array
    {
        $out = [];
        foreach (self::SLOTS as $slot) {
            $out[$slot['by']] = $slot['label'] . ' by';
            $out[$slot['org']] = $slot['label'] . ' third party';
        }
        return $out;
    }

    public static function providerFormatters(): array
    {
        $org = static fn ($v) => $v ? (string) Db::value('SELECT name FROM organizations WHERE id = ?', [$v]) : '';
        $by = static fn ($v) => self::BY[$v ?? ''] ?? (string) $v;
        $out = [];
        foreach (self::SLOTS as $slot) {
            $out[$slot['by']] = $by;
            $out[$slot['org']] = $org;
        }
        return $out;
    }

    /** Normalized name for duplicate detection: "Penn Twp." == "penn township". */
    public static function nameKey(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $map = ['twp' => 'township', 'twsp' => 'township', 'boro' => 'borough', 'bor' => 'borough', 'mt' => 'mount', 'st' => 'saint'];
        $words = array_map(static fn ($w) => $map[$w] ?? $w, $words);
        return implode(' ', $words);
    }

    /** Tidy display name: expand Twp/Boro, title-case. */
    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $name = preg_replace('/\btwp\.?$/i', 'Township', $name);
        $name = preg_replace('/\bboro\.?$/i', 'Borough', $name);
        return ucwords($name);
    }

    /**
     * Create (or return the existing) municipality for name + county.
     * @return array{id:int, created:bool, name:string}
     */
    public static function findOrCreate(string $name, int $countyId): array
    {
        $key = self::nameKey($name);
        if ($key === '') {
            throw new \InvalidArgumentException('Municipality name is required.');
        }
        if (!Db::value('SELECT 1 FROM counties WHERE id = ?', [$countyId])) {
            throw new \InvalidArgumentException('Choose a county.');
        }
        $existing = Db::one('SELECT id, name FROM municipalities WHERE name_key = ? AND county_id = ?', [$key, $countyId]);
        if ($existing) {
            return ['id' => (int) $existing['id'], 'created' => false, 'name' => $existing['name']];
        }
        $display = self::displayName($name);
        $id = Db::insert('municipalities', ['name' => $display, 'name_key' => $key, 'county_id' => $countyId, 'created_by' => Auth::id()]);
        Activity::event('municipality', $id, "Added $display");
        return ['id' => $id, 'created' => true, 'name' => $display];
    }

    public static function label(array $m): string
    {
        return $m['name'] . ' (' . $m['county'] . ' Co., ' . $m['state_code'] . ')';
    }
}
