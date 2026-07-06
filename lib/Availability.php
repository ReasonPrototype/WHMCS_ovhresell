<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Keeps each ovhvps product's orderability in sync with OVH stock.
 *
 * For every product it asks OVH `GET /vps/order/rule/datacenter` whether the
 * plan has stock in any datacenter, then drives WHMCS's native stock control:
 *   - out of stock -> stockcontrol=1, qty=0  => WHMCS shows "Out of Stock" and
 *     disables ordering, but the product stays visible in the store.
 *   - in stock     -> stockcontrol=1, qty=999 => orderable again.
 *
 * Runs from the cron, throttled to once per hour, so availability flips
 * automatically when stock returns. The raw OVH response is logged so the
 * (otherwise undocumented) response shape can be confirmed against real data.
 */
class Availability
{
    private const TTL_SECONDS = 3600;
    private const META_LAST_RUN = 'availability_last_run';
    private const IN_STOCK_QTY = 999;

    /**
     * Refresh only if the last run was more than an hour ago.
     *
     * @return array{skipped?:bool, checked?:int, inStock?:int, outOfStock?:int}
     */
    public static function refreshIfDue(): array
    {
        Helper::init();
        $last = (int) Database::getMeta(self::META_LAST_RUN, '0');
        if (time() - $last < self::TTL_SECONDS) {
            return ['skipped' => true];
        }
        return self::refresh();
    }

    /**
     * Check every ovhvps product and update its WHMCS stock state.
     *
     * @return array{checked:int, inStock:int, outOfStock:int}
     */
    public static function refresh(): array
    {
        Helper::init();
        $now = date('Y-m-d H:i:s');

        $products = Capsule::table('tblproducts')->where('servertype', 'ovhvps')->get();
        $checked = 0;
        $inStock = 0;
        $outOfStock = 0;
        $memo = [];

        foreach ($products as $product) {
            $params = Helper::paramsForProduct((int) $product->id);
            if ($params === null) {
                continue;
            }
            $cfg = Helper::cfg($params);
            $planCode = trim($cfg['plan_code']);
            if ($planCode === '') {
                continue;
            }
            $endpoint = Helper::endpointKey($params);
            $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'PT'));
            $key = $endpoint . '|' . $subsidiary . '|' . $planCode;

            try {
                if (!isset($memo[$key])) {
                    $client = OvhClient::fromParams($params);
                    $memo[$key] = self::checkPlan($client, $planCode, $subsidiary);
                    Database::saveAvailability(
                        $endpoint,
                        $subsidiary,
                        $planCode,
                        $memo[$key]['available'],
                        $memo[$key]['datacenters'],
                        $memo[$key]['raw'],
                        $now
                    );
                }
                $available = $memo[$key]['available'];
                self::applyStock((int) $product->id, $available);
                self::applyDatacenterStock((int) $product->id, $memo[$key]['datacenters']);
                $checked++;
                $available ? $inStock++ : $outOfStock++;
            } catch (\Throwable $e) {
                // Transient error: leave the product's current stock state alone.
                Helper::log('availability:check', ['pid' => $product->id, 'plan' => $planCode], $e->getMessage(), false);
            }
        }

        Database::setMeta(self::META_LAST_RUN, (string) time());
        Helper::log('availability:refresh', null, compact('checked', 'inStock', 'outOfStock'), true);

        return ['checked' => $checked, 'inStock' => $inStock, 'outOfStock' => $outOfStock];
    }

    /**
     * Ask OVH for the per-OS availability matrix for a plan.
     *
     * @return array{available:bool, datacenters:list<array{datacenter:string, linux:bool, windows:bool}>, raw:mixed}
     */
    public static function checkPlan(OvhClient $client, string $planCode, string $subsidiary): array
    {
        $raw = $client->get('/vps/order/rule/datacenter', [
            'planCode' => $planCode,
            'ovhSubsidiary' => $subsidiary,
        ]);
        $matrix = self::parseDatacenterMatrix($raw);
        $available = false;
        foreach ($matrix as $row) {
            if (!empty($row['linux']) || !empty($row['windows'])) {
                $available = true;
                break;
            }
        }
        return [
            'available' => $available,
            'datacenters' => $matrix,
            'raw' => $raw,
        ];
    }

    /**
     * Defensive parse of the vps.order.rule.Datacenters response into a per-OS
     * matrix. Handles a bare list, a {datacenters:[...]} wrapper, list-of-strings
     * and list-of-objects carrying status/linuxStatus/windowsStatus. Each OS is
     * available unless its per-OS status is explicitly unavailable; when a per-OS
     * field is absent it falls back to the generic status. A bare string counts
     * as both OS available. Pure: no WHMCS/HTTP dependency.
     *
     * @param mixed $raw
     * @return list<array{datacenter:string, linux:bool, windows:bool}>
     */
    public static function parseDatacenterMatrix($raw): array
    {
        $list = $raw;
        if (is_array($raw) && isset($raw['datacenters']) && is_array($raw['datacenters'])) {
            $list = $raw['datacenters'];
        }
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $out[] = ['datacenter' => $entry, 'linux' => true, 'windows' => true];
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['datacenter'] ?? $entry['name'] ?? $entry['code'] ?? '');
            if ($name === '') {
                continue;
            }
            $general = strtolower((string) ($entry['status'] ?? $entry['availability'] ?? ''));
            $linuxRaw = strtolower((string) ($entry['linuxStatus'] ?? ''));
            $windowsRaw = strtolower((string) ($entry['windowsStatus'] ?? ''));
            $out[] = [
                'datacenter' => $name,
                'linux' => $linuxRaw !== '' ? !self::statusIsUnavailable($linuxRaw) : !self::statusIsUnavailable($general),
                'windows' => $windowsRaw !== '' ? !self::statusIsUnavailable($windowsRaw) : !self::statusIsUnavailable($general),
            ];
        }
        return $out;
    }

    /**
     * Decode the stored datacenters_json into the matrix shape, tolerating the
     * legacy list-of-strings format (legacy entries count as both OS available).
     *
     * @return list<array{datacenter:string, linux:bool, windows:bool}>
     */
    public static function decodeMatrix(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $entry) {
            if (is_string($entry)) {
                $out[] = ['datacenter' => $entry, 'linux' => true, 'windows' => true];
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['datacenter'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'datacenter' => $name,
                'linux' => !empty($entry['linux']),
                'windows' => !empty($entry['windows']),
            ];
        }
        return $out;
    }

    /**
     * Pure orderability decision for a chosen datacenter (+ optional OS image)
     * against a parsed matrix. Lenient when the matrix is empty (the checkout
     * dry-run is the hard guard). A datacenter absent from a non-empty matrix is
     * not orderable.
     *
     * @param list<array{datacenter:string, linux:bool, windows:bool}> $matrix
     */
    public static function matrixAllows(array $matrix, string $datacenter, ?string $osImage = null): bool
    {
        if ($matrix === []) {
            return true;
        }
        foreach ($matrix as $row) {
            if (strcasecmp((string) ($row['datacenter'] ?? ''), $datacenter) !== 0) {
                continue;
            }
            $linux = !empty($row['linux']);
            $windows = !empty($row['windows']);
            if ($osImage === null) {
                return $linux || $windows;
            }
            return ConfigOptions::osIsWindows($osImage) ? $windows : $linux;
        }
        return false;
    }

    private static function statusIsUnavailable(string $status): bool
    {
        if ($status === '') {
            return false; // no status field -> assume listed means available
        }
        foreach (['unavailable', 'out-of-stock', 'outofstock', 'soldout', 'sold-out', 'comingsoon', 'coming-soon', 'none'] as $bad) {
            if (str_contains($status, $bad)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Order-time guard: false only when we have a fresh "unavailable" verdict.
     * Unknown/never-checked => true (don't block; the checkout dry-run is the
     * hard safety net).
     */
    public static function isOrderable(string $endpoint, string $subsidiary, string $planCode): bool
    {
        $row = Database::getAvailability($endpoint, $subsidiary, $planCode);
        if ($row === null) {
            return true;
        }
        return !empty($row['available']);
    }

    /**
     * Order-time guard for the chosen datacenter and (optionally) OS image.
     * Lenient when we have no data (the checkout dry-run is the hard guard).
     */
    public static function isDatacenterOrderable(string $endpoint, string $subsidiary, string $planCode, string $datacenter, ?string $osImage = null): bool
    {
        $row = Database::getAvailability($endpoint, $subsidiary, $planCode);
        if ($row === null) {
            return true;
        }
        $matrix = self::decodeMatrix((string) ($row['datacenters_json'] ?? '[]'));
        return self::matrixAllows($matrix, $datacenter, $osImage);
    }

    /**
     * Resolve the chosen datacenter code and OS image for a product from the
     * cart's selected configurable options, mapping WHMCS option values to OVH
     * values via mod_ovhvps_option_map. Returns nulls when not resolvable (the
     * caller stays lenient). Logs its input/output so the cart shape can be
     * confirmed against the live Module Log.
     *
     * @param array<int|string, mixed> $configoptions cart product's configoptions
     * @return array{datacenter: ?string, os: ?string}
     */
    public static function cartSelection(int $pid, array $configoptions): array
    {
        $result = ['datacenter' => null, 'os' => null];

        $gid = Capsule::table('tblproductconfiglinks')->where('pid', $pid)->value('gid');
        if (!$gid) {
            return $result;
        }
        $groups = Capsule::table('tblproductconfigoptions')
            ->where('gid', $gid)
            ->whereIn('optionname', [ConfigOptions::GROUP_DATACENTER, ConfigOptions::GROUP_OS])
            ->get();

        foreach ($groups as $group) {
            $chosen = $configoptions[$group->id] ?? null;
            if ($chosen === null) {
                continue;
            }
            // Dropdown selections arrive as the sub-option id.
            $sub = Capsule::table('tblproductconfigoptionssub')->where('id', (int) $chosen)->first();
            $value = $sub
                ? ConfigOptions::stripMarker((string) $sub->optionname)
                : ConfigOptions::stripMarker((string) $chosen);

            $ovhLabel = ($group->optionname === ConfigOptions::GROUP_DATACENTER) ? 'vps_datacenter' : 'vps_os';
            $ovhValue = Capsule::table(Database::OPTION_MAP)
                ->where('pid', $pid)
                ->where('ovh_label', $ovhLabel)
                ->where('whmcs_option_value', $value)
                ->value('ovh_value');
            $resolved = $ovhValue !== null ? (string) $ovhValue : $value;

            if ($ovhLabel === 'vps_datacenter') {
                $result['datacenter'] = $resolved;
            } else {
                $result['os'] = $resolved;
            }
        }
        Helper::log('availability:cartSelection', ['pid' => $pid, 'configoptions' => $configoptions], $result, true);
        return $result;
    }

    /**
     * Build the front-end stock payload for the cart: per-product the per-OS
     * matrix (keyed by lowercased OVH datacenter code) and the datacenter
     * value-to-code map, so the JS can react without another API call.
     *
     * @return array{matrix: array<int, array<string, array{linux:bool, windows:bool}>>, dc: array<int, array<string, string>>}
     */
    public static function cartStockData(): array
    {
        $out = ['matrix' => [], 'dc' => []];
        $products = Capsule::table('tblproducts')->where('servertype', 'ovhvps')->get();
        foreach ($products as $product) {
            $params = Helper::paramsForProduct((int) $product->id);
            if ($params === null) {
                continue;
            }
            $cfg = Helper::cfg($params);
            $planCode = trim($cfg['plan_code']);
            if ($planCode === '') {
                continue;
            }
            $endpoint = Helper::endpointKey($params);
            $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'PT'));
            $row = Database::getAvailability($endpoint, $subsidiary, $planCode);
            if ($row === null) {
                continue;
            }
            $byCode = [];
            foreach (self::decodeMatrix((string) ($row['datacenters_json'] ?? '[]')) as $m) {
                $byCode[strtolower((string) $m['datacenter'])] = [
                    'linux' => (bool) $m['linux'],
                    'windows' => (bool) $m['windows'],
                ];
            }
            $out['matrix'][(int) $product->id] = $byCode;

            $dcMap = [];
            $rows = Capsule::table(Database::OPTION_MAP)
                ->where('pid', $product->id)
                ->where('ovh_label', 'vps_datacenter')
                ->get();
            foreach ($rows as $r) {
                $dcMap[ConfigOptions::stripMarker((string) $r->whmcs_option_value)] = strtolower((string) $r->ovh_value);
            }
            $out['dc'][(int) $product->id] = $dcMap;
        }
        return $out;
    }

    /**
     * Mark out-of-stock datacenters as "<name> - Fora de Stock" in the product's
     * generated "Datacenter" option (and restore the clean name when stock
     * returns). A datacenter is out of stock only when BOTH Linux and Windows are
     * unavailable; per-OS blocking is handled by the cart JS. No-op if config
     * options were not generated.
     *
     * @param list<array{datacenter:string, linux:bool, windows:bool}> $matrix
     */
    private static function applyDatacenterStock(int $pid, array $matrix): void
    {
        $maps = Capsule::table(Database::OPTION_MAP)
            ->where('pid', $pid)
            ->where('ovh_label', 'vps_datacenter')
            ->get();
        if ($maps->isEmpty()) {
            return;
        }

        $gid = Capsule::table('tblproductconfiglinks')->where('pid', $pid)->value('gid');
        if (!$gid) {
            return;
        }
        $configId = Capsule::table('tblproductconfigoptions')
            ->where('gid', $gid)
            ->where('optionname', ConfigOptions::GROUP_DATACENTER)
            ->value('id');
        if (!$configId) {
            return;
        }

        $orderable = [];
        foreach ($matrix as $row) {
            if (!empty($row['linux']) || !empty($row['windows'])) {
                $orderable[] = strtolower((string) ($row['datacenter'] ?? ''));
            }
        }
        $marker = ConfigOptions::OOS_MARKER;
        foreach ($maps as $map) {
            $canonical = (string) $map->whmcs_option_value;
            $code = strtolower((string) $map->ovh_value);
            $outOfStock = !in_array($code, $orderable, true);
            $desired = $canonical . ($outOfStock ? $marker : '');

            Capsule::table('tblproductconfigoptionssub')
                ->where('configid', $configId)
                ->where(static function ($q) use ($canonical, $marker): void {
                    $q->where('optionname', $canonical)
                        ->orWhere('optionname', $canonical . $marker);
                })
                ->update(['optionname' => $desired, 'hidden' => 0]);
        }
    }

    /**
     * Drive WHMCS native stock control for a product.
     */
    private static function applyStock(int $pid, bool $available): void
    {
        Capsule::table('tblproducts')->where('id', $pid)->update([
            'stockcontrol' => 1,
            'qty' => $available ? self::IN_STOCK_QTY : 0,
        ]);
    }
}
