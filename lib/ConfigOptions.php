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
     * Families we never sell or reinstall: OS images bundling a control-panel
     * license (cPanel/Plesk) that OVH would bill us for and that we do not
     * resell. Checked wherever images become customer-facing choices (the
     * generated Operating System dropdown and the reinstall list).
     */
    public const EXCLUDED_FAMILIES = ['cpanel', 'plesk'];

    /**
     * Families locked to the ordered OS: Windows is sellable (its paid license
     * is attached to the order), but the lock works BOTH ways at reinstall
     * time. Only a service ordered with Windows may reinstall Windows (a
     * reinstall can never create a license OVH bills us for outside an order),
     * and a Windows service may ONLY reinstall Windows (its license keeps
     * billing either way, and leaving would be a one-way door because the
     * inbound lock would then refuse the way back).
     */
    public const LOCKED_FAMILIES = ['windows'];

    /**
     * The special image family of an OS image name, or '' for a plain
     * distribution (Debian, Ubuntu, Rocky, ...). The token only IDENTIFIES the
     * family; what a family is allowed to do lives in EXCLUDED_FAMILIES /
     * LOCKED_FAMILIES and their consumers. The free application images
     * (Docker, n8n) are ordinary sellable Linux images; their token exists so
     * the n8n client-area tab and the n8n email can key off the installed
     * image. Each token must be distinctive enough not to collide with a plain
     * distro name. Pure: no WHMCS/DB dependency.
     */
    public static function imageFamily(string $image): string
    {
        $image = strtolower($image);
        foreach (['windows', 'cpanel', 'plesk', 'docker', 'n8n'] as $family) {
            if (str_contains($image, $family)) {
                return $family;
            }
        }
        return '';
    }

    /**
     * Whether an OS image may be offered to customers at all (order dropdown
     * and reinstall list). Only the excluded panel families are refused; the
     * Windows order-lock is a reinstall-time rule, not a sell-time rule.
     * Pure: no WHMCS/DB dependency.
     */
    public static function isSellableImage(string $image): bool
    {
        return !self::hasExcludedFamilyToken($image);
    }

    /**
     * Whether an image name carries ANY excluded-family token (cPanel/Plesk),
     * independent of which single family imageFamily() classifies it as, so a
     * hypothetical "Windows + Plesk" bundle is refused too. Pure.
     */
    public static function hasExcludedFamilyToken(string $image): bool
    {
        $image = strtolower($image);
        foreach (self::EXCLUDED_FAMILIES as $family) {
            if (str_contains($image, $family)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reinstall policy between the CURRENTLY INSTALLED image and a target
     * image. Excluded panel families (cPanel/Plesk) are never allowed. A
     * locked family (Windows) locks BOTH ways: a service running a locked
     * image may only reinstall inside that family, and a service outside it
     * may never enter it. Everything else (plain distros and the free app
     * images Docker/n8n) is freely interchangeable, which is what lets a
     * customer move in and out of Docker/n8n at will. Pure: no WHMCS/DB
     * dependency.
     */
    public static function canReinstallTo(string $currentImage, string $targetImage): bool
    {
        if (self::hasExcludedFamilyToken($targetImage)) {
            return false;
        }
        $current = self::imageFamily($currentImage);
        $target = self::imageFamily($targetImage);
        if (in_array($current, self::LOCKED_FAMILIES, true) || in_array($target, self::LOCKED_FAMILIES, true)) {
            return $target === $current;
        }
        return true;
    }

    /** Normalise an OS name for matching (lowercase, collapse whitespace). Pure. */
    public static function normalizeOsName(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim($name)));
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
     * Pure: did the customer buy the paid OVH option behind a family? True when
     * the chosen sub-option value resolves to an option-kind option_map row with
     * a non-empty planCode ("None" carries an empty planCode, so it is not a
     * purchase; config-kind rows like OS/datacenter never count). Pure so it is
     * unit-tested without WHMCS.
     *
     * @param list<array<string,mixed>> $mapRows option_map rows for the product
     */
    public static function familyPurchasedFrom(string $group, ?string $selectedValue, array $mapRows): bool
    {
        if ($selectedValue === null) {
            return false;
        }
        $value = self::stripMarker(trim($selectedValue));
        foreach ($mapRows as $row) {
            if (($row['ovh_kind'] ?? '') !== 'option') {
                continue;
            }
            if ((string) ($row['whmcs_option_group'] ?? '') !== $group) {
                continue;
            }
            if ((string) ($row['whmcs_option_value'] ?? '') !== $value) {
                continue;
            }
            return trim((string) ($row['ovh_option_plan_code'] ?? '')) !== '';
        }
        return false;
    }

    /**
     * Whether the service purchased the paid OVH option behind a family (e.g.
     * "snapshot", "veeam"). Reads the live WHMCS service config options
     * (authoritative, unlike $params['configoptions'] which the client area may
     * omit) and resolves the chosen sub-option against the option_map. Only pass
     * families with NO free tier: automatedBackup ships a free Standard tier and
     * must never be gated. Used to lock the client-area tabs that need a paid
     * option behind what the customer actually bought.
     */
    public static function isFamilyPurchased(int $serviceId, int $pid, string $family): bool
    {
        if ($serviceId <= 0 || $pid <= 0) {
            return false;
        }
        Helper::init();
        $group = ucfirst($family);
        $selectedValue = Capsule::table('tblhostingconfigoptions as h')
            ->join('tblproductconfigoptions as co', 'co.id', '=', 'h.configid')
            ->join('tblproductconfigoptionssub as sub', 'sub.id', '=', 'h.optionid')
            ->where('h.relid', $serviceId)
            ->where('co.optionname', $group)
            ->value('sub.optionname');
        $mapRows = Capsule::table(Database::OPTION_MAP)
            ->where('pid', $pid)
            ->get()
            ->map(static fn ($r) => (array) $r)
            ->all();

        return self::familyPurchasedFrom($group, $selectedValue === null ? null : (string) $selectedValue, $mapRows);
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
     * record the OVH mapping. Idempotent per product: re-runs SYNC the existing
     * group in place, so sub-options that already exist KEEP their configured
     * prices, new ones are added at price 0, and ones no longer offered (e.g.
     * excluded families) are removed together with their pricing rows.
     *
     * NOTE: writes WHMCS core tables (tblproductconfig*). Verify against a live
     * WHMCS install.
     *
     * @return array{group_id:int, os:int, datacenters:int, options:int}
     */
    public static function generate(int $pid, string $endpoint, string $subsidiary, string $planCode): array
    {
        Helper::init();

        $os = Catalog::getOs($endpoint, $subsidiary, $planCode);
        // Never offer an image whose family we refuse to sell (cPanel/Plesk).
        $os = array_values(array_filter($os, [self::class, 'isSellableImage']));
        $datacenters = Catalog::getDatacenters($endpoint, $subsidiary, $planCode);
        $options = Catalog::getOptions($endpoint, $subsidiary, $planCode);

        // Fail safe: with an empty OS or Datacenter list (unsynced catalog, bad
        // plan code, plan retired by OVH) a sync would leave the PREVIOUS
        // generation's sub-options orderable but with no option_map rows, so a
        // customer's choice would be silently dropped at order time. Refuse
        // instead of desyncing.
        if ($os === [] || $datacenters === []) {
            throw new \RuntimeException(
                'The cached OVH catalog has no OS images or datacenters for plan "'
                . $planCode . '". Run Sync Catalog (or check the VPS Plan Code) and try again.'
            );
        }

        // The `os` addon family is the mandatory OS license line (free Linux vs paid
        // Windows). It is not offered as a dropdown; instead each OS image below carries
        // its implied license so the customer cannot mismatch the image and the license.
        $osFamily = array_values(array_filter(
            $options,
            static fn (array $o): bool => ($o['family'] ?? '') === 'os'
        ));
        $licenses = self::pickOsLicenses($osFamily);

        $groupName = 'OVH VPS #' . $pid;

        // Upsert: sync an existing group in place so configured prices survive
        // a re-generation; only brand-new sub-options start at price 0.
        $existing = Capsule::table('tblproductconfiggroups')->where('name', $groupName)->first();
        if ($existing) {
            $gid = (int) $existing->id;
            if (!Capsule::table('tblproductconfiglinks')->where('gid', $gid)->where('pid', $pid)->exists()) {
                Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $pid]);
            }
        } else {
            $gid = (int) Capsule::table('tblproductconfiggroups')->insertGetId([
                'name' => $groupName,
                'description' => 'Auto-generated from the OVH catalog for product #' . $pid,
            ]);
            Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $pid]);
        }
        // The OVH mapping carries no pricing: rebuild it deterministically.
        Capsule::table(Database::OPTION_MAP)->where('pid', $pid)->delete();

        $osCount = self::syncDropdown($pid, $gid, self::GROUP_OS, array_map(
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
        $dcCount = self::syncDropdown($pid, $gid, self::GROUP_DATACENTER, array_map(
            static fn (string $v): array => ['label' => Datacenters::label($v), 'kind' => 'config', 'ovh_label' => 'vps_datacenter', 'ovh_value' => $v],
            $datacenters
        ));

        $desired = [self::GROUP_OS, self::GROUP_DATACENTER];
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
            $optCount += self::syncDropdown($pid, $gid, ucfirst($family), $subs);
            $desired[] = ucfirst($family);
        }

        // Drop dropdowns that are no longer generated (family gone from the
        // catalog), including their sub-options and pricing rows.
        $stale = Capsule::table('tblproductconfigoptions')
            ->where('gid', $gid)->whereNotIn('optionname', $desired)->pluck('id');
        foreach ($stale as $oid) {
            $subIds = Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->pluck('id');
            foreach ($subIds as $sid) {
                Capsule::table('tblpricing')->where('type', 'configoptions')->where('relid', $sid)->delete();
            }
            Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->delete();
            Capsule::table('tblproductconfigoptions')->where('id', $oid)->delete();
        }

        Helper::log('configoptions:generate', ['pid' => $pid, 'plan' => $planCode], [
            'os' => $osCount, 'datacenters' => $dcCount, 'options' => $optCount,
        ], true);

        return ['group_id' => $gid, 'os' => $osCount, 'datacenters' => $dcCount, 'options' => $optCount];
    }

    /**
     * Create or sync one configurable option (dropdown). Existing sub-options
     * (matched by name with the out-of-stock marker stripped, so a datacenter
     * renamed by the stock pass still matches) are KEPT with their pricing rows
     * untouched; missing ones are inserted at price 0; ones no longer desired
     * are deleted together with their pricing. The option-map entries are
     * rewritten from scratch by the caller's delete + these inserts.
     *
     * @param list<array<string,string>> $subOptions
     */
    private static function syncDropdown(int $pid, int $gid, string $optionName, array $subOptions): int
    {
        if (!$subOptions) {
            return 0;
        }

        $configId = (int) (Capsule::table('tblproductconfigoptions')
            ->where('gid', $gid)->where('optionname', $optionName)->value('id') ?? 0);
        if ($configId === 0) {
            $configId = (int) Capsule::table('tblproductconfigoptions')->insertGetId([
                'gid' => $gid,
                'optionname' => $optionName,
                'optiontype' => 1, // dropdown
                'qtyminimum' => 0,
                'qtymaximum' => 0,
                'hidden' => 0,
            ]);
        }

        // Existing sub-options keyed by canonical (marker-stripped) name. The
        // stored name is kept as-is; the stock pass owns the visible marker.
        $existingSubs = [];
        foreach (Capsule::table('tblproductconfigoptionssub')->where('configid', $configId)->get() as $row) {
            $existingSubs[self::stripMarker((string) $row->optionname)] = (int) $row->id;
        }

        $sort = 0;
        $kept = [];
        foreach ($subOptions as $sub) {
            $label = $sub['label'];
            if (isset($existingSubs[$label])) {
                $subId = $existingSubs[$label];
                Capsule::table('tblproductconfigoptionssub')->where('id', $subId)
                    ->update(['sortorder' => $sort++, 'hidden' => 0]);
            } else {
                $subId = (int) Capsule::table('tblproductconfigoptionssub')->insertGetId([
                    'configid' => $configId,
                    'optionname' => $label,
                    'sortorder' => $sort++,
                    'hidden' => 0,
                ]);
                self::zeroPricing('configoptions', $subId);
            }
            $kept[$subId] = true;

            Capsule::table(Database::OPTION_MAP)->insert([
                'pid' => $pid,
                'whmcs_option_group' => $optionName,
                'whmcs_option_value' => $label,
                'ovh_kind' => $sub['kind'],
                'ovh_label' => $sub['ovh_label'] ?? null,
                'ovh_value' => $sub['ovh_value'] ?? null,
                'ovh_option_plan_code' => $sub['ovh_option_plan_code'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Remove sub-options no longer offered (e.g. an excluded family), with
        // their pricing rows.
        foreach ($existingSubs as $subId) {
            if (!isset($kept[$subId])) {
                Capsule::table('tblpricing')->where('type', 'configoptions')->where('relid', $subId)->delete();
                Capsule::table('tblproductconfigoptionssub')->where('id', $subId)->delete();
            }
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
