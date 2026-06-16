<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Executes WHMCS upgrades on an existing OVH VPS. Phase 1: add paid options
 * (backup, snapshot, additional disk, IP, Veeam) to a delivered VPS via the OVH
 * cartServiceOption flow. Add-only - removals and model downgrades are not
 * supported (the OVH API does not allow them) and are refused with a message.
 *
 * Mirrors {@see Provisioning}: a thin WHMCS-facing entrypoint (apply) plus
 * focused helpers, with the pure diff (detectChange) kept dependency-free so it
 * is unit-testable offline.
 */
class Upgrade
{
    /**
     * Pure diff between what the service currently has and what the (new) WHMCS
     * product/options want. Add-only: anything that would reduce or remove is
     * surfaced in optionsToRemove for the caller to reject.
     *
     * @param list<array{planCode:string,quantity:int}> $currentOptions
     * @param list<array{planCode:string,quantity:int}> $desiredOptions
     * @return array{planUpgrade:?string, optionsToAdd:list<array{planCode:string,quantity:int}>, optionsToRemove:list<string>, noop:bool}
     */
    public static function detectChange(?string $currentPlan, ?string $targetPlan, array $currentOptions, array $desiredOptions): array
    {
        $cur = self::foldQty($currentOptions);
        $des = self::foldQty($desiredOptions);

        $toAdd = [];
        $toRemove = [];

        foreach ($des as $planCode => $qty) {
            $delta = $qty - ($cur[$planCode] ?? 0);
            if ($delta > 0) {
                $toAdd[] = ['planCode' => $planCode, 'quantity' => $delta];
            } elseif ($delta < 0) {
                $toRemove[] = $planCode;
            }
        }
        foreach ($cur as $planCode => $qty) {
            if (!isset($des[$planCode])) {
                $toRemove[] = $planCode;
            }
        }

        $planUpgrade = ($targetPlan !== null && $targetPlan !== '' && $targetPlan !== $currentPlan)
            ? $targetPlan
            : null;

        return [
            'planUpgrade' => $planUpgrade,
            'optionsToAdd' => $toAdd,
            'optionsToRemove' => $toRemove,
            'noop' => $planUpgrade === null && $toAdd === [] && $toRemove === [],
        ];
    }

    /**
     * WHMCS ChangePackage entrypoint. Resolves the live VPS, diffs the new
     * product/options against what's provisioned, and (Phase 1) applies new paid
     * options. Plan/model changes and any removal/downgrade are refused with a
     * clear message rather than silently desyncing WHMCS from OVH.
     *
     * @param array<string,mixed> $params server-module params (the NEW product/options)
     * @return array{success:bool, message:string}
     */
    public static function apply(array $params): array
    {
        Helper::init();
        $serviceId = (int) ($params['serviceid'] ?? 0);

        $server = Database::getServer($serviceId);
        if (!$server || empty($server['service_name'])) {
            return ['success' => false, 'message' => 'This service has no delivered VPS yet; cannot upgrade (still provisioning?).'];
        }
        $serviceName = (string) $server['service_name'];

        $cfg = Helper::cfg($params);
        $endpoint = Helper::endpointKey($params);
        $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'FR'));
        $targetPlan = trim($cfg['plan_code']);
        $currentPlan = (string) ($server['plan_code'] ?? '');

        $desiredOptions = ConfigOptions::parse($params)['options'];
        $currentOptions = self::decodeOptions($server['options_json'] ?? null);

        $change = self::detectChange($currentPlan, $targetPlan, $currentOptions, $desiredOptions);

        if ($change['noop']) {
            return ['success' => true, 'message' => 'Nothing to upgrade.'];
        }
        if ($change['optionsToRemove'] !== []) {
            return ['success' => false, 'message' => 'Removing or reducing options is not supported (OVH options are cancelled separately): ' . implode(', ', $change['optionsToRemove'])];
        }
        if ($change['planUpgrade'] !== null) {
            // Phase 2 builds the in-place model resize; until then refuse loudly.
            return ['success' => false, 'message' => 'Model/plan upgrades are not enabled yet (Phase 2). Configure only "Configurable Option Upgrades" for now.'];
        }

        $cycle = Helper::billingCycle($params);
        $months = $cycle !== '' ? Term::billingCycleMonths($cycle) : null;

        // Options are priced/attached against the VPS's current model; fall back
        // to the target plan only if the model was never recorded.
        $vpsPlanCode = $currentPlan !== '' ? $currentPlan : $targetPlan;
        if ($vpsPlanCode === '') {
            return ['success' => false, 'message' => 'Cannot price options: no VPS plan code recorded for this service or configured on the product.'];
        }

        $client = OvhClient::fromParams($params);
        $result = self::addOptions(
            $client,
            $serviceId,
            $serviceName,
            $endpoint,
            $subsidiary,
            $vpsPlanCode,
            $months,
            $change['optionsToAdd'],
            $currentOptions
        );

        Helper::log('upgrade:apply', ['service' => $serviceName, 'add' => $change['optionsToAdd']], $result, $result['success'], $serviceId);
        return ['success' => $result['success'], 'message' => $result['message']];
    }

    /**
     * Fold a list of {planCode, quantity} into a planCode => total-quantity map.
     *
     * @param list<array{planCode:string,quantity:int}> $options
     * @return array<string,int>
     */
    public static function foldQty(array $options): array
    {
        $map = [];
        foreach ($options as $opt) {
            $planCode = (string) ($opt['planCode'] ?? '');
            if ($planCode === '') {
                continue;
            }
            $map[$planCode] = ($map[$planCode] ?? 0) + max(1, (int) ($opt['quantity'] ?? 1));
        }
        return $map;
    }

    /**
     * Decode a stored options_json blob into a list of {planCode, quantity}.
     *
     * @return list<array{planCode:string,quantity:int}>
     */
    public static function decodeOptions(?string $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $opt) {
            if (is_array($opt) && isset($opt['planCode'])) {
                $out[] = ['planCode' => (string) $opt['planCode'], 'quantity' => max(1, (int) ($opt['quantity'] ?? 1))];
            }
        }
        return $out;
    }

    /**
     * Add each new paid option to an existing VPS via the OVH cartServiceOption
     * flow (one dedicated, auto-paid cart per option). Gated by what OVH says is
     * orderable for THIS service, with a dry-run before every checkout so a wrong
     * combo never charges. Persists the applied options so re-runs are idempotent.
     *
     * @param list<array{planCode:string,quantity:int}> $optionsToAdd
     * @param list<array{planCode:string,quantity:int}> $currentOptions already-provisioned (for the persisted merge)
     * @return array{success:bool, added:list<array{planCode:string,quantity:int}>, message:string}
     */
    public static function addOptions(OvhClient $client, int $serviceId, string $serviceName, string $endpoint, string $subsidiary, string $planCode, ?int $months, array $optionsToAdd, array $currentOptions): array
    {
        // What OVH will actually let us order for THIS service right now.
        $orderable = self::orderablePlanCodes($client, $serviceName);

        $applied = [];
        $errors = [];

        foreach ($optionsToAdd as $opt) {
            $optPlan = (string) $opt['planCode'];
            $qty = max(1, (int) $opt['quantity']);

            if ($orderable !== [] && !in_array($optPlan, $orderable, true)) {
                $errors[] = $optPlan . ' is not orderable for this VPS right now';
                continue;
            }

            // Match the option's term to the service's billing cycle, falling back
            // to monthly/default when the addon only sells that.
            $optResolved = $months !== null
                ? Term::pickPricing(Catalog::getOptionPricings($endpoint, $subsidiary, $planCode, $optPlan), $months)
                : null;
            $duration = $optResolved['duration'] ?? 'P1M';
            $pricingMode = $optResolved['pricingMode'] ?? 'default';

            try {
                $cart = (array) $client->post('/order/cart', [
                    'ovhSubsidiary' => $subsidiary,
                    'description' => 'WHMCS upgrade service ' . $serviceId,
                ]);
                $cartId = (string) ($cart['cartId'] ?? '');
                if ($cartId === '') {
                    $errors[] = $optPlan . ': OVH did not return a cartId';
                    continue;
                }

                // Attach the option to the EXISTING service. (Exact contract
                // confirmed against the live OVH API.)
                $client->post('/order/cartServiceOption/vps/' . $serviceName, [
                    'cartId' => $cartId,
                    'planCode' => $optPlan,
                    'duration' => $duration,
                    'pricingMode' => $pricingMode,
                    'quantity' => $qty,
                ]);

                $client->post('/order/cart/' . $cartId . '/assign');

                // Dry-run (capture cost for margin auditing) then auto-paid checkout.
                $dryRun = (array) $client->get('/order/cart/' . $cartId . '/checkout');
                [$expectedCost, $currency] = Provisioning::extractPrice($dryRun);
                $checkout = (array) $client->post('/order/cart/' . $cartId . '/checkout', [
                    'autoPayWithPreferredPaymentMethod' => true,
                    'waiveRetractationPeriod' => true,
                ]);
                $orderId = (string) ($checkout['orderId'] ?? '');

                self::recordOrder($serviceId, 'upgrade-option', $cartId, $orderId, [
                    'planCode' => $optPlan,
                    'quantity' => $qty,
                    'duration' => $duration,
                    'pricingMode' => $pricingMode,
                ], $expectedCost, $currency, $dryRun);

                if ($orderId === '') {
                    $errors[] = $optPlan . ': cart submitted but OVH returned no orderId';
                    continue;
                }
                $applied[] = ['planCode' => $optPlan, 'quantity' => $qty];
            } catch (\Throwable $e) {
                Helper::log('upgrade:addOption', ['service' => $serviceName, 'option' => $optPlan], $e->getMessage(), false, $serviceId);
                $errors[] = $optPlan . ': ' . $e->getMessage();
            }
        }

        // Persist the new option set (current + applied) so the next ChangePackage
        // diffs against reality and never re-orders what's already there.
        if ($applied !== []) {
            Database::upsertServer($serviceId, ['options_json' => json_encode(self::mergeOptions($currentOptions, $applied))]);
        }

        return [
            'success' => $errors === [],
            'added' => $applied,
            'message' => $errors === []
                ? 'Added ' . count($applied) . ' option(s): ' . implode(', ', array_column($applied, 'planCode')) . '.'
                : 'Added ' . count($applied) . ', ' . count($errors) . ' failed: ' . implode('; ', $errors),
        ];
    }

    /**
     * planCodes OVH currently lets us order for this service (cartServiceOption).
     * Returns [] when the listing can't be read, so the caller falls back to the
     * dry-run as the safety net rather than blocking every option.
     *
     * @return list<string>
     */
    private static function orderablePlanCodes(OvhClient $client, string $serviceName): array
    {
        try {
            $list = $client->get('/order/cartServiceOption/vps/' . $serviceName);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_array($entry) && isset($entry['planCode'])) {
                    $out[] = (string) $entry['planCode'];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<array{planCode:string,quantity:int}> $a
     * @param list<array{planCode:string,quantity:int}> $b
     * @return list<array{planCode:string,quantity:int}>
     */
    private static function mergeOptions(array $a, array $b): array
    {
        $out = [];
        foreach (self::foldQty(array_merge($a, $b)) as $planCode => $qty) {
            $out[] = ['planCode' => $planCode, 'quantity' => $qty];
        }
        return $out;
    }

    /**
     * Insert an order row - its own row per upgrade, never overwriting the create
     * order (service_id is an index, not unique). Records expected_cost/currency
     * from the dry-run, mirroring the margin auditing Provisioning does for create
     * orders.
     *
     * @param array<string,mixed> $request
     * @param mixed $response
     */
    private static function recordOrder(int $serviceId, string $kind, string $cartId, string $orderId, array $request, ?float $expectedCost, ?string $currency, $response): void
    {
        Capsule::table(Database::ORDERS)->insert([
            'service_id' => $serviceId,
            'cart_id' => $cartId !== '' ? $cartId : null,
            'order_id' => $orderId !== '' ? $orderId : null,
            'status' => $orderId !== '' ? 'checking' : 'failed',
            'kind' => $kind,
            'expected_cost' => $expectedCost,
            'currency' => $currency,
            'request_json' => json_encode($request),
            'response_json' => $response !== null ? (is_string($response) ? $response : json_encode($response)) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
