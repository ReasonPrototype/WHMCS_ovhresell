<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Daily watchdog over the OVH public catalog for the plans this WHMCS actually
 * sells. It compares the LIVE catalog against the locally cached copy the
 * store options were generated from and detects upstream changes: a plan
 * withdrawn from sale, OS images added or retired, addons added or removed,
 * and any price change on the plan or its addons. When something changed it
 * emails the WHMCS admins ONE summary, including a WHMCS-price vs OVH-price
 * margin table, so the reseller can re-check the margins and then accept the
 * new catalog (Sync Catalog + Generate OVH options). It never modifies the
 * cache or the products by itself.
 *
 * Alerts are de-duplicated with a fingerprint of the live state: one email per
 * distinct upstream state. The fingerprint re-arms as soon as the admin syncs
 * the catalog (cache matches live again) or OVH changes something else.
 *
 * The diff helpers are pure (no WHMCS/HTTP dependency) and unit-tested offline
 * in tests/watchdog_test.php.
 */
class Watchdog
{
    private const TTL_SECONDS = 86400; // one automatic check per day
    private const META_LAST_RUN = 'watchdog_last_run';
    private const META_FP_PREFIX = 'watchdog_fp:';

    /** OVH public-catalog prices are longs scaled by 10^8 (350000000 = 3.50). */
    public const PRICE_DIVISOR = 100000000;

    /**
     * Run only if the last run was more than a day ago (called from the cron).
     *
     * @return array{skipped?:bool, groups?:int, alerted?:int}
     */
    public static function runIfDue(): array
    {
        Helper::init();
        $last = (int) Database::getMeta(self::META_LAST_RUN, '0');
        if (time() - $last < self::TTL_SECONDS) {
            return ['skipped' => true];
        }
        return self::run();
    }

    /**
     * Check every endpoint+subsidiary this install sells from. The last-run
     * stamp is written up front so an OVH outage cannot turn every cron tick
     * into a failing catalog download.
     *
     * @return array{groups:int, alerted:int}
     */
    public static function run(): array
    {
        Helper::init();
        Database::setMeta(self::META_LAST_RUN, (string) time());

        // Group the configured products by endpoint+subsidiary: one catalog
        // download and one alert email per group, listing every plan it sells.
        $products = Capsule::table('tblproducts')->where('servertype', 'ovhvps')->get();
        $groups = [];
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
            $key = $endpoint . '|' . $subsidiary;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'params' => $params,
                    'endpoint' => $endpoint,
                    'subsidiary' => $subsidiary,
                    'products' => [],
                ];
            }
            $groups[$key]['products'][] = [
                'pid' => (int) $product->id,
                'name' => (string) $product->name,
                'plan_code' => $planCode,
            ];
        }

        $checked = 0;
        $alerted = 0;
        foreach ($groups as $key => $group) {
            try {
                $checked++;
                if (self::checkGroup($group)) {
                    $alerted++;
                }
            } catch (\Throwable $e) {
                // Transient OVH/API error: try again on the next daily run.
                Helper::log('watchdog:check', ['group' => $key], $e->getMessage(), false);
            }
        }
        Helper::log('watchdog:run', null, ['groups' => $checked, 'alerted' => $alerted], true);

        return ['groups' => $checked, 'alerted' => $alerted];
    }

    /**
     * Diff one endpoint+subsidiary's live catalog against the cache and alert
     * the admins when a sold plan changed upstream. Returns true when an alert
     * email was sent.
     *
     * @param array{params:array<string,mixed>, endpoint:string, subsidiary:string,
     *              products:list<array{pid:int, name:string, plan_code:string}>} $group
     */
    private static function checkGroup(array $group): bool
    {
        $endpoint = $group['endpoint'];
        $subsidiary = $group['subsidiary'];

        // Without a synced cache there is no baseline to diff against; the
        // admin syncs it once when generating the product options.
        if (Catalog::getPlans($endpoint, $subsidiary) === []) {
            Helper::log('watchdog:skip', ['endpoint' => $endpoint, 'subsidiary' => $subsidiary], 'catalog never synced; no baseline', true);
            return false;
        }

        $client = OvhClient::fromParams($group['params']);
        $catalog = (array) $client->get('/order/catalog/public/vps', ['ovhSubsidiary' => $subsidiary]);
        $live = Catalog::parse($catalog);
        $currency = (string) ($catalog['locale']['currencyCode'] ?? 'EUR');

        $planCodes = array_values(array_unique(array_column($group['products'], 'plan_code')));

        $changesByPlan = [];
        $liveSnapshots = [];
        foreach ($planCodes as $planCode) {
            $liveSnapshots[$planCode] = self::livePlanSnapshot($live, $planCode);
            $diff = self::diffPlan(
                self::cachedPlanSnapshot($endpoint, $subsidiary, $planCode),
                $liveSnapshots[$planCode]
            );
            if ($diff['changed']) {
                $changesByPlan[$planCode] = $diff;
            }
        }

        $fpKey = self::META_FP_PREFIX . $endpoint . '|' . $subsidiary;
        if ($changesByPlan === []) {
            // Cache matches live again for everything we sell: re-arm the
            // alert so the NEXT upstream change emails again.
            Database::setMeta($fpKey, '');
            return false;
        }

        // One alert per distinct upstream state: skip when we already emailed
        // about exactly this live state and the admin has not re-synced yet.
        $fingerprint = md5((string) json_encode($liveSnapshots));
        if (Database::getMeta($fpKey, '') === $fingerprint) {
            return false;
        }

        $margins = self::marginRows($group['products'], $live);
        $msg = self::adminMessage($subsidiary, $changesByPlan, $margins, $currency);
        self::sendAdminAlert($msg['subject'], $msg['body']);
        Database::setMeta($fpKey, $fingerprint);
        Helper::log('watchdog:alert', ['endpoint' => $endpoint, 'subsidiary' => $subsidiary], array_keys($changesByPlan), true);

        return true;
    }

    /**
     * Snapshot of one plan as the CACHE knows it (what the store was generated
     * from). Shape shared with livePlanSnapshot().
     *
     * @return array{exists:bool, os:list<string>, pricings:array<int,array<string,mixed>>,
     *               options:array<string, array{description:string, pricings:array<int,array<string,mixed>>}>}
     */
    private static function cachedPlanSnapshot(string $endpoint, string $subsidiary, string $planCode): array
    {
        $exists = Capsule::table(Database::CAT_PLANS)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->exists();

        $options = [];
        foreach (Catalog::getOptions($endpoint, $subsidiary, $planCode) as $row) {
            $code = (string) ($row['option_plan_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $options[$code] = [
                'description' => (string) (($row['description'] ?? '') ?: $code),
                'pricings' => self::pricingsFrom((string) ($row['raw_json'] ?? '')),
            ];
        }
        ksort($options);

        return [
            'exists' => $exists,
            'os' => array_map('strval', Catalog::getOs($endpoint, $subsidiary, $planCode)),
            'pricings' => Catalog::getPlanPricings($endpoint, $subsidiary, $planCode),
            'options' => $options,
        ];
    }

    /**
     * Pure: extract the same snapshot shape from a parsed live catalog
     * ({@see Catalog::parse} output).
     *
     * @param array{plans?:list<array<string,mixed>>, os?:list<array<string,mixed>>, options?:list<array<string,mixed>>} $parsed
     * @return array{exists:bool, os:list<string>, pricings:array<int,array<string,mixed>>,
     *               options:array<string, array{description:string, pricings:array<int,array<string,mixed>>}>}
     */
    public static function livePlanSnapshot(array $parsed, string $planCode): array
    {
        $exists = false;
        $pricings = [];
        foreach (($parsed['plans'] ?? []) as $row) {
            if ((string) ($row['plan_code'] ?? '') === $planCode) {
                $exists = true;
                $pricings = self::pricingsFrom((string) ($row['raw_json'] ?? ''));
                break;
            }
        }

        $os = [];
        foreach (($parsed['os'] ?? []) as $row) {
            if ((string) ($row['plan_code'] ?? '') === $planCode) {
                $os[] = (string) ($row['os'] ?? '');
            }
        }
        sort($os);

        $options = [];
        foreach (($parsed['options'] ?? []) as $row) {
            if ((string) ($row['plan_code'] ?? '') !== $planCode) {
                continue;
            }
            $code = (string) ($row['option_plan_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $options[$code] = [
                'description' => (string) (($row['description'] ?? '') ?: $code),
                'pricings' => self::pricingsFrom((string) ($row['raw_json'] ?? '')),
            ];
        }
        ksort($options);

        return ['exists' => $exists, 'os' => $os, 'pricings' => $pricings, 'options' => $options];
    }

    /**
     * Pure diff between two plan snapshots. Reports the plan disappearing, OS
     * images added/removed, addons added/removed, and price changes on the
     * plan and on every kept addon. When the whole plan is missing live, the
     * per-item removals are suppressed (the headline already covers them).
     *
     * @param array{exists:bool, os:list<string>, pricings:array, options:array<string,array>} $cached
     * @param array{exists:bool, os:list<string>, pricings:array, options:array<string,array>} $live
     * @return array{changed:bool, missing:bool, os_added:list<string>, os_removed:list<string>,
     *               options_added:list<string>, options_removed:list<string>,
     *               plan_price_changes:list<array{term:string, old:?int, new:?int}>,
     *               option_price_changes:array<string, list<array{term:string, old:?int, new:?int}>>}
     */
    public static function diffPlan(array $cached, array $live): array
    {
        $missing = !empty($cached['exists']) && empty($live['exists']);

        $cachedOs = array_map('strval', $cached['os'] ?? []);
        $liveOs = array_map('strval', $live['os'] ?? []);
        $osAdded = array_values(array_diff($liveOs, $cachedOs));
        $osRemoved = $missing ? [] : array_values(array_diff($cachedOs, $liveOs));

        $cachedOpts = is_array($cached['options'] ?? null) ? $cached['options'] : [];
        $liveOpts = is_array($live['options'] ?? null) ? $live['options'] : [];
        $optAdded = array_values(array_diff(array_keys($liveOpts), array_keys($cachedOpts)));
        $optRemoved = $missing ? [] : array_values(array_diff(array_keys($cachedOpts), array_keys($liveOpts)));

        $planPrice = $missing ? [] : self::priceChanges($cached['pricings'] ?? [], $live['pricings'] ?? []);

        $optPrice = [];
        if (!$missing) {
            foreach ($cachedOpts as $code => $c) {
                if (!isset($liveOpts[$code])) {
                    continue;
                }
                $delta = self::priceChanges($c['pricings'] ?? [], $liveOpts[$code]['pricings'] ?? []);
                if ($delta !== []) {
                    $optPrice[(string) $code] = $delta;
                }
            }
        }

        $changed = $missing || $osAdded !== [] || $osRemoved !== [] || $optAdded !== []
            || $optRemoved !== [] || $planPrice !== [] || $optPrice !== [];

        return [
            'changed' => $changed,
            'missing' => $missing,
            'os_added' => $osAdded,
            'os_removed' => $osRemoved,
            'options_added' => $optAdded,
            'options_removed' => $optRemoved,
            'plan_price_changes' => $planPrice,
            'option_price_changes' => $optPrice,
        ];
    }

    /**
     * Pure: index a catalog `pricings` array by sellable term (mode + order
     * duration) onto its raw price. Only renewable entries matter: one-shot
     * installation lines never affect the recurring margin.
     *
     * @param array<int, mixed> $pricings
     * @return array<string, int>
     */
    public static function pricingKeyMap(array $pricings): array
    {
        $map = [];
        foreach ($pricings as $p) {
            if (!is_array($p)) {
                continue;
            }
            $caps = $p['capacities'] ?? [];
            if (!is_array($caps) || !in_array('renew', $caps, true)) {
                continue;
            }
            $key = (string) ($p['mode'] ?? 'default') . ' ' . Term::resolveOrderDuration($p);
            $map[$key] = (int) ($p['price'] ?? 0);
        }
        return $map;
    }

    /**
     * Pure: every term whose raw price differs between the two pricing sets,
     * including terms OVH added (old null) or withdrew (new null).
     *
     * @param array<int, mixed> $oldPricings
     * @param array<int, mixed> $newPricings
     * @return list<array{term:string, old:?int, new:?int}>
     */
    public static function priceChanges(array $oldPricings, array $newPricings): array
    {
        $old = self::pricingKeyMap($oldPricings);
        $new = self::pricingKeyMap($newPricings);
        $out = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $term) {
            $o = $old[$term] ?? null;
            $n = $new[$term] ?? null;
            if ($o !== $n) {
                $out[] = ['term' => (string) $term, 'old' => $o, 'new' => $n];
            }
        }
        return $out;
    }

    /**
     * Pure: the plan's no-commitment monthly price from a pricings array, in
     * currency units (pre-tax), or null when the plan has no such term.
     *
     * @param array<int, mixed> $pricings
     */
    public static function monthlyPrice(array $pricings): ?float
    {
        foreach ($pricings as $p) {
            if (!is_array($p)) {
                continue;
            }
            $caps = $p['capacities'] ?? [];
            if (!is_array($caps) || !in_array('renew', $caps, true)) {
                continue;
            }
            if ((int) ($p['commitment'] ?? 0) !== 0) {
                continue;
            }
            if (Term::resolveOrderDuration($p) !== 'P1M') {
                continue;
            }
            return ((int) ($p['price'] ?? 0)) / self::PRICE_DIVISOR;
        }
        return null;
    }

    /** Pure: a raw catalog price long as a printable amount ('n/a' when absent). */
    public static function formatPrice(?int $raw): string
    {
        return $raw === null ? 'n/a' : number_format($raw / self::PRICE_DIVISOR, 2, '.', '');
    }

    /**
     * The WHMCS-vs-OVH monthly price per configured product, for the margin
     * table in the alert email. The WHMCS side reads the product's monthly
     * price in the first currency; -1 (cycle not offered) becomes null.
     *
     * @param list<array{pid:int, name:string, plan_code:string}> $products
     * @param array<string, mixed> $live parsed live catalog
     * @return list<array{product:string, plan:string, whmcs:?float, ovh:?float}>
     */
    private static function marginRows(array $products, array $live): array
    {
        $rows = [];
        foreach ($products as $p) {
            $snapshot = self::livePlanSnapshot($live, $p['plan_code']);
            $whmcs = Capsule::table('tblpricing')
                ->where('type', 'product')->where('relid', $p['pid'])
                ->orderBy('currency')->value('monthly');
            $rows[] = [
                'product' => (string) $p['name'],
                'plan' => (string) $p['plan_code'],
                'whmcs' => is_numeric($whmcs) && (float) $whmcs >= 0 ? (float) $whmcs : null,
                'ovh' => self::monthlyPrice($snapshot['pricings']),
            ];
        }
        return $rows;
    }

    /**
     * Pure builder for the admin alert email. Prices are shown pre-tax in the
     * catalog currency. Admin-facing, so English only (project convention).
     *
     * @param array<string, array<string, mixed>> $changesByPlan diffPlan() results keyed by planCode
     * @param list<array{product:string, plan:string, whmcs:?float, ovh:?float}> $margins
     * @return array{subject:string, body:string}
     */
    public static function adminMessage(string $subsidiary, array $changesByPlan, array $margins, string $currency): array
    {
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $money = static fn (?float $v): string => $v === null ? 'n/a' : number_format($v, 2, '.', '');

        $body = '<p>OVH changed the public VPS catalog (subsidiary ' . $esc($subsidiary)
            . ') for plans this WHMCS sells. Review the changes and your margins below, then run '
            . '<strong>Sync Catalog</strong> + <strong>Generate OVH options</strong> on the affected '
            . 'products to accept the new catalog. Until then the store keeps selling from the '
            . 'previous copy.</p>';

        foreach ($changesByPlan as $planCode => $diff) {
            $body .= '<p><strong>' . $esc($planCode) . '</strong></p><ul>';
            if (!empty($diff['missing'])) {
                $body .= '<li><strong>Plan withdrawn:</strong> it no longer appears in the OVH catalog. '
                    . 'New orders will fail; consider hiding or retiring the product.</li>';
            }
            foreach (($diff['os_added'] ?? []) as $os) {
                $body .= '<li>OS image added: ' . $esc($os) . '</li>';
            }
            foreach (($diff['os_removed'] ?? []) as $os) {
                $body .= '<li><strong>OS image retired:</strong> ' . $esc($os) . '</li>';
            }
            foreach (($diff['options_added'] ?? []) as $code) {
                $body .= '<li>Addon added: ' . $esc($code) . '</li>';
            }
            foreach (($diff['options_removed'] ?? []) as $code) {
                $body .= '<li><strong>Addon removed:</strong> ' . $esc($code) . '</li>';
            }
            foreach (($diff['plan_price_changes'] ?? []) as $chg) {
                $body .= '<li><strong>Plan price change</strong> (' . $esc($chg['term']) . '): '
                    . self::formatPrice($chg['old']) . ' to ' . self::formatPrice($chg['new'])
                    . ' ' . $esc($currency) . '</li>';
            }
            foreach (($diff['option_price_changes'] ?? []) as $code => $changes) {
                foreach ($changes as $chg) {
                    $body .= '<li><strong>Addon price change</strong> ' . $esc($code)
                        . ' (' . $esc($chg['term']) . '): ' . self::formatPrice($chg['old'])
                        . ' to ' . self::formatPrice($chg['new']) . ' ' . $esc($currency) . '</li>';
                }
            }
            $body .= '</ul>';
        }

        if ($margins !== []) {
            $body .= '<p><strong>Monthly margin recap</strong> (your WHMCS price vs the current OVH '
                . 'price, pre-tax, no-commitment term):</p>'
                . '<table border="1" cellpadding="6" cellspacing="0">'
                . '<tr><th>Product</th><th>Plan</th><th>WHMCS monthly</th><th>OVH monthly ('
                . $esc($currency) . ')</th><th>Margin</th></tr>';
            foreach ($margins as $row) {
                $margin = ($row['whmcs'] !== null && $row['ovh'] !== null)
                    ? $money($row['whmcs'] - $row['ovh'])
                    : 'n/a';
                $body .= '<tr><td>' . $esc($row['product']) . '</td><td>' . $esc($row['plan'])
                    . '</td><td>' . $money($row['whmcs']) . '</td><td>' . $money($row['ovh'])
                    . '</td><td>' . $margin . '</td></tr>';
            }
            $body .= '</table>'
                . '<p>Note: the WHMCS price is in your store currency and the OVH price is the '
                . 'pre-tax catalog price; treat the margin column as an approximation and check '
                . 'the recorded per-order costs for exact values.</p>';
        }

        $body .= '<p>This alert is sent once per catalog change; it repeats only when OVH changes '
            . 'something else (or after you re-sync and OVH changes again).</p>';

        return [
            'subject' => 'OVH VPS: catalog changed for ' . count($changesByPlan) . ' plan(s) you sell - check your margins',
            'body' => $body,
        ];
    }

    /** Decode a raw_json blob's `pricings` array, or []. */
    private static function pricingsFrom(string $raw)
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
     * Notify the WHMCS admins via the SendAdminEmail API (no template setup
     * needed), logging the real outcome like every other module email.
     */
    private static function sendAdminAlert(string $subject, string $body): void
    {
        if (!function_exists('localAPI')) {
            return;
        }
        $result = localAPI('SendAdminEmail', [
            'customsubject' => $subject,
            'custommessage' => $body,
            'type' => 'system',
        ]);
        $ok = (($result['result'] ?? '') === 'success');
        Helper::log('watchdog:email', ['subject' => $subject], $result, $ok);
    }
}
