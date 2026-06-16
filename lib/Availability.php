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
            $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'FR'));
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
     * Ask OVH whether a plan is orderable in any datacenter.
     *
     * @return array{available:bool, datacenters:list<string>, raw:mixed}
     */
    public static function checkPlan(OvhClient $client, string $planCode, string $subsidiary): array
    {
        $raw = $client->get('/vps/order/rule/datacenter', [
            'planCode' => $planCode,
            'ovhSubsidiary' => $subsidiary,
        ]);
        $datacenters = self::parseInStockDatacenters($raw);
        return [
            'available' => count($datacenters) > 0,
            'datacenters' => $datacenters,
            'raw' => $raw,
        ];
    }

    /**
     * Defensive parse of the (undocumented) vps.order.rule.Datacenters response.
     * Handles a bare list, a {datacenters:[...]} wrapper, list-of-strings and
     * list-of-objects with status/availability fields. Anything not explicitly
     * unavailable counts as in stock.
     *
     * @param mixed $raw
     * @return list<string> in-stock datacenter codes
     */
    public static function parseInStockDatacenters($raw): array
    {
        $list = $raw;
        if (is_array($raw) && isset($raw['datacenters']) && is_array($raw['datacenters'])) {
            $list = $raw['datacenters'];
        }
        if (!is_array($list)) {
            return [];
        }

        $inStock = [];
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $inStock[] = $entry;
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['datacenter'] ?? $entry['name'] ?? $entry['code'] ?? '');
            $status = strtolower((string) ($entry['status'] ?? $entry['availability'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (self::statusIsUnavailable($status)) {
                continue;
            }
            $inStock[] = $name;
        }
        return array_values(array_unique($inStock));
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
     * Order-time guard for the chosen datacenter. Lenient when we have no
     * datacenter data (the checkout dry-run is the hard guard).
     */
    public static function isDatacenterOrderable(string $endpoint, string $subsidiary, string $planCode, string $datacenter): bool
    {
        $row = Database::getAvailability($endpoint, $subsidiary, $planCode);
        if ($row === null) {
            return true;
        }
        $datacenters = json_decode((string) ($row['datacenters_json'] ?? '[]'), true);
        if (!is_array($datacenters) || $datacenters === []) {
            return true;
        }
        foreach ($datacenters as $dc) {
            if (strcasecmp((string) $dc, $datacenter) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark out-of-stock datacenters as "<name> - Fora de Stock" in the product's
     * generated "Datacenter" configurable option (and restore the clean name
     * when stock returns). The options stay visible; ovhvps.stock.js disables any
     * option whose text carries the marker, so the customer sees them but cannot
     * pick one OVH can't deliver. No-op if config options weren't generated.
     *
     * @param list<string> $inStock in-stock datacenter codes
     */
    private static function applyDatacenterStock(int $pid, array $inStock): void
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

        $inStockSet = array_map('strtolower', $inStock);
        $marker = ConfigOptions::OOS_MARKER;
        foreach ($maps as $map) {
            $canonical = (string) $map->whmcs_option_value;
            $code = strtolower((string) $map->ovh_value);
            $outOfStock = !in_array($code, $inStockSet, true);
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
