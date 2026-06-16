<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Syncs the OVH public VPS catalog (plans, datacenters, OS, add-on options)
 * into local cache tables and reads it back for the admin/config UI.
 *
 * Source of truth: GET /order/catalog/public/vps?ovhSubsidiary=XX which returns
 * per-plan `configurations` (vps_datacenter / vps_os allowed values) and
 * `addonFamilies` linking to top-level `addons` (planCodes for backup, snapshot,
 * additional disk, IP, Veeam...).
 */
class Catalog
{
    /**
     * Pure transform: turn a raw catalog payload into flat rows ready for cache.
     * Kept side-effect-free so it can be unit tested without a database.
     *
     * @param array<string, mixed> $catalog Decoded /order/catalog/public/vps payload.
     * @return array{plans: list<array<string,mixed>>, datacenters: list<array<string,mixed>>, os: list<array<string,mixed>>, options: list<array<string,mixed>>}
     */
    public static function parse(array $catalog): array
    {
        $plans = $catalog['plans'] ?? [];
        $addonIndex = self::indexAddons($catalog['addons'] ?? []);

        $out = ['plans' => [], 'datacenters' => [], 'os' => [], 'options' => []];

        foreach ($plans as $plan) {
            $planCode = $plan['planCode'] ?? null;
            if (!is_string($planCode) || $planCode === '') {
                continue;
            }

            $out['plans'][] = [
                'plan_code' => $planCode,
                'description' => (string) ($plan['invoiceName'] ?? $planCode),
                'raw_json' => json_encode($plan),
            ];

            foreach (($plan['configurations'] ?? []) as $conf) {
                $name = strtolower((string) ($conf['name'] ?? ''));
                $values = $conf['values'] ?? [];
                if (!is_array($values)) {
                    continue;
                }
                if (str_contains($name, 'datacenter')) {
                    foreach ($values as $dc) {
                        $out['datacenters'][] = [
                            'plan_code' => $planCode,
                            'datacenter' => (string) $dc,
                            'name' => (string) $dc,
                            'in_stock' => 1,
                        ];
                    }
                } elseif ($name === 'vps_os' || str_contains($name, 'os')) {
                    foreach ($values as $os) {
                        $out['os'][] = [
                            'plan_code' => $planCode,
                            'os' => (string) $os,
                            'available' => 1,
                        ];
                    }
                }
            }

            foreach (($plan['addonFamilies'] ?? []) as $family) {
                $familyName = (string) ($family['name'] ?? '');
                $kind = self::classifyFamily($familyName);
                foreach (($family['addons'] ?? []) as $addonCode) {
                    $addonCode = (string) $addonCode;
                    $addon = $addonIndex[$addonCode] ?? null;
                    $out['options'][] = [
                        'plan_code' => $planCode,
                        'family' => $kind,
                        'option_plan_code' => $addonCode,
                        'description' => (string) ($addon['invoiceName'] ?? $addonCode),
                        'raw_json' => $addon ? json_encode($addon) : null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Fetch the catalog from OVH and (re)populate the cache for this
     * endpoint+subsidiary.
     *
     * @return array{plans:int, datacenters:int, os:int, options:int}
     */
    public static function sync(OvhClient $client, string $endpoint, string $subsidiary): array
    {
        Helper::init();
        $now = date('Y-m-d H:i:s');

        $catalog = (array) $client->get('/order/catalog/public/vps', ['ovhSubsidiary' => $subsidiary]);
        $rows = self::parse($catalog);

        foreach ([Database::CAT_PLANS, Database::CAT_DATACENTERS, Database::CAT_OS, Database::CAT_OPTIONS] as $table) {
            Capsule::table($table)
                ->where('endpoint', $endpoint)
                ->where('subsidiary', $subsidiary)
                ->delete();
        }

        $stamp = static function (array $row) use ($endpoint, $subsidiary, $now): array {
            return array_merge($row, [
                'endpoint' => $endpoint,
                'subsidiary' => $subsidiary,
                'synced_at' => $now,
            ]);
        };

        foreach ($rows['plans'] as $r) {
            Capsule::table(Database::CAT_PLANS)->insert($stamp($r));
        }
        foreach ($rows['datacenters'] as $r) {
            Capsule::table(Database::CAT_DATACENTERS)->insert($stamp($r));
        }
        foreach ($rows['os'] as $r) {
            Capsule::table(Database::CAT_OS)->insert($stamp($r));
        }
        foreach ($rows['options'] as $r) {
            Capsule::table(Database::CAT_OPTIONS)->insert($stamp($r));
        }

        $counts = [
            'plans' => count($rows['plans']),
            'datacenters' => count($rows['datacenters']),
            'os' => count($rows['os']),
            'options' => count($rows['options']),
        ];
        Helper::log('catalog:sync', ['endpoint' => $endpoint, 'subsidiary' => $subsidiary], $counts, true);

        return $counts;
    }

    /**
     * Live stock/availability per datacenter for a given plan+OS.
     * GET /vps/order/rule/datacenter (returns datacenters with availability).
     *
     * @return array<int, mixed>
     */
    public static function datacenterAvailability(OvhClient $client, string $planCode, string $os = '', string $datacenter = '', string $duration = 'P1M'): array
    {
        $query = array_filter([
            'planCode' => $planCode,
            'os' => $os,
            'datacenter' => $datacenter,
            'duration' => $duration,
        ], static fn ($v) => $v !== '');
        return (array) $client->get('/vps/order/rule/datacenter', $query);
    }

    /** @return list<array<string,mixed>> */
    public static function getPlans(string $endpoint, string $subsidiary): array
    {
        return self::rows(Database::CAT_PLANS, $endpoint, $subsidiary);
    }

    /** @return list<string> */
    public static function getDatacenters(string $endpoint, string $subsidiary, string $planCode): array
    {
        return Capsule::table(Database::CAT_DATACENTERS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->orderBy('datacenter')->pluck('datacenter')->all();
    }

    /** @return list<string> */
    public static function getOs(string $endpoint, string $subsidiary, string $planCode): array
    {
        return Capsule::table(Database::CAT_OS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->orderBy('os')->pluck('os')->all();
    }

    /** @return list<array<string,mixed>> */
    public static function getOptions(string $endpoint, string $subsidiary, string $planCode): array
    {
        return Capsule::table(Database::CAT_OPTIONS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->orderBy('family')->get()->map(static fn ($r) => (array) $r)->all();
    }

    /**
     * The cached `pricings` array for a plan (from CAT_PLANS.raw_json), or [].
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getPlanPricings(string $endpoint, string $subsidiary, string $planCode): array
    {
        $raw = Capsule::table(Database::CAT_PLANS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->value('raw_json');
        return self::pricingsFromRawJson(is_string($raw) ? $raw : '');
    }

    /**
     * The cached `pricings` array for one addon (from CAT_OPTIONS.raw_json), or [].
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getOptionPricings(string $endpoint, string $subsidiary, string $planCode, string $optionPlanCode): array
    {
        $raw = Capsule::table(Database::CAT_OPTIONS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)
            ->where('plan_code', $planCode)->where('option_plan_code', $optionPlanCode)
            ->value('raw_json');
        return self::pricingsFromRawJson(is_string($raw) ? $raw : '');
    }

    /** @return array<int, array<string, mixed>> */
    private static function pricingsFromRawJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && isset($decoded['pricings']) && is_array($decoded['pricings'])
            ? $decoded['pricings']
            : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function rows(string $table, string $endpoint, string $subsidiary): array
    {
        return Capsule::table($table)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)
            ->get()->map(static fn ($r) => (array) $r)->all();
    }

    /**
     * @param array<int, array<string,mixed>> $addons
     * @return array<string, array<string,mixed>>
     */
    private static function indexAddons(array $addons): array
    {
        $index = [];
        foreach ($addons as $addon) {
            if (isset($addon['planCode']) && is_string($addon['planCode'])) {
                $index[$addon['planCode']] = $addon;
            }
        }
        return $index;
    }

    public static function classifyFamily(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'snapshot')) {
            return 'snapshot';
        }
        if (str_contains($n, 'veeam')) {
            return 'veeam';
        }
        if (str_contains($n, 'backup') || str_contains($n, 'automated')) {
            return 'backup';
        }
        if (str_contains($n, 'disk') || str_contains($n, 'storage') || str_contains($n, 'volume')) {
            return 'disk';
        }
        if (str_contains($n, 'ip')) {
            return 'ip';
        }
        return $n !== '' ? $n : 'other';
    }
}
