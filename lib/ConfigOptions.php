<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Bridges WHMCS Configurable Options (what the customer picks: OS, datacenter,
 * extras) and the OVH order cart.
 *
 * - generate(): builds WHMCS configurable options for a product from the cached
 *   catalog and records the OVH mapping in mod_ovhvps_option_map.
 * - parse()/map(): translate the customer's chosen options into OVH cart
 *   "config" items (vps_datacenter, vps_os) and "options" (addon planCodes).
 *
 * The pure {@see ConfigOptions::map()} contains the translation logic and is
 * unit-tested without a database.
 */
class ConfigOptions
{
    public const GROUP_OS = 'Operating System';
    public const GROUP_DATACENTER = 'Datacenter';

    /** Suffix appended to an out-of-stock option's visible name. */
    public const OOS_MARKER = ' - Fora de Stock';

    /** Strip the out-of-stock marker so a selected value still maps to OVH. */
    public static function stripMarker(string $value): string
    {
        // strpos is sufficient because OOS_MARKER is a plain ASCII/Latin string.
        $pos = strpos($value, self::OOS_MARKER);
        return $pos !== false ? rtrim(substr($value, 0, $pos)) : $value;
    }

    /**
     * From the catalog's `os` addon-family rows, pick the Linux and Windows license
     * planCodes. Each member is identified by "windows"/"linux" appearing in its
     * planCode or description. Pure: no WHMCS/DB dependency.
     *
     * @param list<array<string, mixed>> $osFamilyRows rows with option_plan_code/description
     * @return array{windows: ?string, linux: ?string}
     */
    public static function pickOsLicenses(array $osFamilyRows): array
    {
        $licenses = ['windows' => null, 'linux' => null];
        foreach ($osFamilyRows as $row) {
            $code = (string) ($row['option_plan_code'] ?? '');
            $hay = strtolower($code . ' ' . (string) ($row['description'] ?? ''));
            if ($licenses['windows'] === null && str_contains($hay, 'windows')) {
                $licenses['windows'] = $code;
            } elseif ($licenses['linux'] === null && str_contains($hay, 'linux')) {
                $licenses['linux'] = $code;
            }
        }
        return $licenses;
    }

    /**
     * Whether an OS image is a Windows image (so it needs the paid Windows
     * license and Windows datacenter capacity). Single source of truth for the
     * Linux-vs-Windows decision, shared by impliedLicense(), the availability
     * matrix guard, and (mirrored) the cart JS. Pure: no WHMCS/DB dependency.
     */
    public static function osIsWindows(string $image): bool
    {
        return stripos($image, 'windows') !== false;
    }

    /**
     * The OS license planCode implied by a chosen OS image: a Windows image needs the
     * paid Windows license, anything else maps to the (free) Linux license. Returns
     * null when the required planCode is absent (e.g. a legacy plan with no os family).
     * Pure: no WHMCS/DB dependency.
     *
     * @param array{windows: ?string, linux: ?string} $licenses
     * @return ?string
     */
    public static function impliedLicense(string $image, array $licenses): ?string
    {
        if (self::osIsWindows($image)) {
            return $licenses['windows'] ?? null;
        }
        return $licenses['linux'] ?? null;
    }

    /**
     * Pure mapping: customer selections + option-map rows -> OVH cart items.
     *
     * @param array<string, string|int> $selected $params['configoptions'] (group name => chosen value/qty)
     * @param list<array<string, mixed>> $mapRows mod_ovhvps_option_map rows for the product
     * @return array{config: list<array{label:string,value:string}>, options: list<array{planCode:string,quantity:int}>}
     */
    public static function map(array $selected, array $mapRows): array
    {
        $config = [];
        $options = [];

        foreach ($selected as $group => $value) {
            $value = is_string($value) ? self::stripMarker(trim($value)) : $value;
            $rows = array_values(array_filter(
                $mapRows,
                static fn (array $r): bool => ($r['whmcs_option_group'] ?? '') === $group
            ));
            if (!$rows) {
                continue;
            }

            // Config dropdown (OS / Datacenter): match the chosen visible value.
            foreach ($rows as $row) {
                if (($row['ovh_kind'] ?? '') !== 'config') {
                    continue;
                }
                if ((string) ($row['whmcs_option_value'] ?? '') === (string) $value) {
                    $config[] = [
                        'label' => (string) $row['ovh_label'],
                        'value' => (string) $row['ovh_value'],
                    ];
                    // An OS image may imply a mandatory license addon (free Linux or
                    // paid Windows) stored on the same row; emit it alongside the config.
                    $implied = (string) ($row['ovh_option_plan_code'] ?? '');
                    if ($implied !== '') {
                        $options[] = ['planCode' => $implied, 'quantity' => 1];
                    }
                    continue 2;
                }
            }

            // Option families (snapshot, backup, disk, ip, veeam).
            foreach ($rows as $row) {
                if (($row['ovh_kind'] ?? '') !== 'option') {
                    continue;
                }
                $planCode = (string) ($row['ovh_option_plan_code'] ?? '');
                if ($planCode === '') {
                    continue;
                }
                $rowValue = (string) ($row['whmcs_option_value'] ?? '');

                if ($rowValue !== '') {
                    // Dropdown-style family: only add when the chosen value matches.
                    if ($rowValue === (string) $value) {
                        $options[] = ['planCode' => $planCode, 'quantity' => 1];
                    }
                    continue;
                }

                // Quantity-style family (e.g. additional IPs): value is the qty.
                $qty = is_numeric($value) ? (int) $value : (self::isAffirmative((string) $value) ? 1 : 0);
                if ($qty > 0) {
                    $options[] = ['planCode' => $planCode, 'quantity' => $qty];
                }
            }
        }

        return ['config' => $config, 'options' => $options];
    }

    /**
     * Translate the live WHMCS service params into OVH cart items.
     *
     * @param array<string, mixed> $params Server module $params (has 'configoptions' and 'pid').
     * @return array{config: list<array{label:string,value:string}>, options: list<array{planCode:string,quantity:int}>}
     */
    public static function parse(array $params): array
    {
        Helper::init();
        $pid = (int) ($params['pid'] ?? 0);
        $selected = isset($params['configoptions']) && is_array($params['configoptions'])
            ? $params['configoptions']
            : [];

        $mapRows = Capsule::table(Database::OPTION_MAP)
            ->where('pid', $pid)
            ->get()
            ->map(static fn ($r) => (array) $r)
            ->all();

        return self::map($selected, $mapRows);
    }

    /**
     * Build the dropdown sub-options for one addon family from its catalog rows.
     *
     * A family OVH flags as mandatory (the cart must carry one of its addons, e.g.
     * storage on vps-2027-model3) is offered WITHOUT a "None" choice and leads with
     * its catalog default, so an order can never miss a required addon (the
     * "Addon of type storage is missing" checkout error). An optional family keeps
     * "None" first, so the customer is never silently charged for an extra they did
     * not pick. Pure: no WHMCS/DB dependency.
     *
     * @param list<array<string,mixed>> $addons CAT_OPTIONS rows for a single family
     * @return list<array{label:string,kind:string,ovh_option_plan_code:string}>
     */
    public static function familySubOptions(array $addons): array
    {
        $mandatory = false;
        foreach ($addons as $addon) {
            if (!empty($addon['mandatory'])) {
                $mandatory = true;
                break;
            }
        }

        // Lead with the OVH-default addon; the sort is stable (PHP 8.0+) so the
        // remaining addons keep their catalog order.
        usort($addons, static fn (array $a, array $b): int =>
            (!empty($b['is_default']) ? 1 : 0) <=> (!empty($a['is_default']) ? 1 : 0));

        $subs = [];
        if (!$mandatory) {
            // Optional family: customer may decline it (no charge by default).
            $subs[] = ['label' => 'None', 'kind' => 'option', 'ovh_option_plan_code' => ''];
        }
        foreach ($addons as $addon) {
            $planCode = (string) ($addon['option_plan_code'] ?? '');
            if ($planCode === '') {
                continue;
            }
            $subs[] = [
                'label' => ((string) ($addon['description'] ?? '')) ?: $planCode,
                'kind' => 'option',
                'ovh_option_plan_code' => $planCode,
            ];
        }
        return $subs;
    }

    /**
     * Build WHMCS configurable options for a product from the cached catalog and
     * record the OVH mapping. Idempotent per product (re-runs replace the group).
     *
     * NOTE: writes WHMCS core tables (tblproductconfig*). Verify against a live
     * WHMCS install; prices default to 0 (admin sets the markup afterwards).
     *
     * @return array{group_id:int, os:int, datacenters:int, options:int}
     */
    public static function generate(int $pid, string $endpoint, string $subsidiary, string $planCode): array
    {
        Helper::init();

        $os = Catalog::getOs($endpoint, $subsidiary, $planCode);
        $datacenters = Catalog::getDatacenters($endpoint, $subsidiary, $planCode);
        $options = Catalog::getOptions($endpoint, $subsidiary, $planCode);

        // The `os` addon family is the mandatory OS license line (free Linux vs paid
        // Windows). It is not offered as a dropdown; instead each OS image below carries
        // its implied license so the customer cannot mismatch the image and the license.
        $osFamily = array_values(array_filter(
            $options,
            static fn (array $o): bool => ($o['family'] ?? '') === 'os'
        ));
        $licenses = self::pickOsLicenses($osFamily);

        $groupName = 'OVH VPS #' . $pid;

        // Reset any previous generation for this product.
        $existing = Capsule::table('tblproductconfiggroups')->where('name', $groupName)->first();
        if ($existing) {
            self::deleteGroup((int) $existing->id);
        }
        Capsule::table(Database::OPTION_MAP)->where('pid', $pid)->delete();

        $gid = (int) Capsule::table('tblproductconfiggroups')->insertGetId([
            'name' => $groupName,
            'description' => 'Auto-generated from the OVH catalog for product #' . $pid,
        ]);
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $pid]);

        $osCount = self::createDropdown($pid, $gid, self::GROUP_OS, array_map(
            static fn (string $v): array => [
                'label' => $v,
                'kind' => 'config',
                'ovh_label' => 'vps_os',
                'ovh_value' => $v,
                'ovh_option_plan_code' => self::impliedLicense($v, $licenses),
            ],
            $os
        ));

        // The visible label becomes a friendly "City, Country" name (Datacenters::label),
        // while ovh_value stays the raw OVH datacenter code. All consumers (map(),
        // cartSelection(), cartStockData(), applyDatacenterStock()) resolve via the
        // option_map's ovh_value, so the order and the stock UX keep working unchanged.
        $dcCount = self::createDropdown($pid, $gid, self::GROUP_DATACENTER, array_map(
            static fn (string $v): array => ['label' => Datacenters::label($v), 'kind' => 'config', 'ovh_label' => 'vps_datacenter', 'ovh_value' => $v],
            $datacenters
        ));

        $optCount = 0;
        foreach (self::groupOptionsByFamily($options) as $family => $addons) {
            if ($family === 'os') {
                // Handled as the implied license on each OS image above.
                continue;
            }
            // One dropdown per family. Mandatory families (storage, ...) omit "None"
            // and lead with the OVH default; optional ones keep "None" first.
            $subs = self::familySubOptions($addons);
            if (!$subs) {
                continue;
            }
            $optCount += self::createDropdown($pid, $gid, ucfirst($family), $subs);
        }

        Helper::log('configoptions:generate', ['pid' => $pid, 'plan' => $planCode], [
            'os' => $osCount, 'datacenters' => $dcCount, 'options' => $optCount,
        ], true);

        return ['group_id' => $gid, 'os' => $osCount, 'datacenters' => $dcCount, 'options' => $optCount];
    }

    /**
     * Create one configurable option (dropdown) with sub-options, zero pricing
     * rows, and matching option-map entries.
     *
     * @param list<array<string,string>> $subOptions
     */
    private static function createDropdown(int $pid, int $gid, string $optionName, array $subOptions): int
    {
        if (!$subOptions) {
            return 0;
        }

        $configId = (int) Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $gid,
            'optionname' => $optionName,
            'optiontype' => 1, // dropdown
            'qtyminimum' => 0,
            'qtymaximum' => 0,
            'hidden' => 0,
        ]);

        $sort = 0;
        foreach ($subOptions as $sub) {
            $subId = (int) Capsule::table('tblproductconfigoptionssub')->insertGetId([
                'configid' => $configId,
                'optionname' => $sub['label'],
                'sortorder' => $sort++,
                'hidden' => 0,
            ]);
            self::zeroPricing('configoptions', $subId);

            Capsule::table(Database::OPTION_MAP)->insert([
                'pid' => $pid,
                'whmcs_option_group' => $optionName,
                'whmcs_option_value' => $sub['label'],
                'ovh_kind' => $sub['kind'],
                'ovh_label' => $sub['ovh_label'] ?? null,
                'ovh_value' => $sub['ovh_value'] ?? null,
                'ovh_option_plan_code' => $sub['ovh_option_plan_code'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return count($subOptions);
    }

    /**
     * Insert zero-amount pricing rows for every active currency so the option is
     * orderable; the admin edits the markup afterwards.
     */
    private static function zeroPricing(string $type, int $relid): void
    {
        $currencies = Capsule::table('tblcurrencies')->pluck('id');
        foreach ($currencies as $currencyId) {
            Capsule::table('tblpricing')->insert([
                'type' => $type,
                'currency' => (int) $currencyId,
                'relid' => $relid,
                'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0,
                'bsetupfee' => 0, 'tsetupfee' => 0,
                'monthly' => 0, 'quarterly' => 0, 'semiannually' => 0,
                'annually' => 0, 'biennially' => 0, 'triennially' => 0,
            ]);
        }
    }

    private static function deleteGroup(int $gid): void
    {
        $optionIds = Capsule::table('tblproductconfigoptions')->where('gid', $gid)->pluck('id');
        foreach ($optionIds as $oid) {
            $subIds = Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->pluck('id');
            foreach ($subIds as $sid) {
                Capsule::table('tblpricing')->where('type', 'configoptions')->where('relid', $sid)->delete();
            }
            Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->delete();
        }
        Capsule::table('tblproductconfigoptions')->where('gid', $gid)->delete();
        Capsule::table('tblproductconfiglinks')->where('gid', $gid)->delete();
        Capsule::table('tblproductconfiggroups')->where('id', $gid)->delete();
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return array<string, list<array<string,mixed>>>
     */
    private static function groupOptionsByFamily(array $options): array
    {
        $byFamily = [];
        foreach ($options as $opt) {
            $family = (string) ($opt['family'] ?? 'other');
            $byFamily[$family][] = $opt;
        }
        return $byFamily;
    }

    private static function isAffirmative(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['yes', 'on', '1', 'true', 'enable', 'enabled'], true);
    }
}
