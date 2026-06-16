<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Orders a VPS through the OVH order cart and resolves the delivered
 * serviceName. Implements the exact sequence:
 *
 *   POST /order/cart -> assign -> add /vps -> read requiredConfiguration ->
 *   POST configuration (one label per call) -> add options ->
 *   GET checkout (dry-run, capture cost) -> POST checkout (autoPay) ->
 *   resolve serviceName (poll /me/order + diff /vps).
 *
 * Progress is recorded in mod_ovhvps_orders so a mid-way failure is debuggable
 * and re-runs are idempotent (a service that already has an order_id is never
 * re-checked-out -> no double billing).
 */
class Provisioning
{
    /**
     * @param array<string, mixed> $params Server module $params.
     * @return array{success:bool, serviceName:?string, status:string, message:string}
     */
    public static function order(array $params): array
    {
        Helper::init();

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $cfg = Helper::cfg($params);
        $endpoint = Helper::endpointKey($params);
        $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'FR'));
        $planCode = trim($cfg['plan_code']);
        $fallbackDuration = trim($cfg['duration'] ?: 'P1M');
        $fallbackPricingMode = trim($cfg['pricing_mode'] ?: 'default');

        if ($planCode === '') {
            return self::fail($serviceId, 'No VPS Plan Code configured on the product Module Settings.');
        }

        // Idempotency: never re-order a service that already has an OVH order.
        $existing = self::getOrder($serviceId);
        if ($existing && !empty($existing['order_id'])) {
            $server = Database::getServer($serviceId);
            return [
                'success' => true,
                'serviceName' => $server['service_name'] ?? null,
                'status' => $existing['status'] ?? 'checking',
                'message' => 'Order already placed (orderId ' . $existing['order_id'] . '); not re-ordering.',
            ];
        }

        // Resolve the OVH commitment to the customer's WHMCS billing cycle: pick
        // the deepest discount whose commitment does not exceed the term the
        // customer paid for. Falls back to the product config when the catalog
        // is not synced or the cycle is unknown. $months is reused for options.
        $cycle = Helper::billingCycle($params);
        $months = $cycle !== '' ? Term::billingCycleMonths($cycle) : null;
        $resolved = $months !== null
            ? Term::pickPricing(Catalog::getPlanPricings($endpoint, $subsidiary, $planCode), $months)
            : null;
        $duration = $resolved['duration'] ?? $fallbackDuration;
        $pricingMode = $resolved['pricingMode'] ?? $fallbackPricingMode;
        Helper::log('term:resolve', ['service_id' => $serviceId, 'cycle' => $cycle, 'months' => $months], ['duration' => $duration, 'pricingMode' => $pricingMode], $resolved !== null, $serviceId);

        // Don't try to buy what OVH currently can't deliver (race-condition guard
        // on top of the WHMCS stock control that should already hide it).
        if (!Availability::isOrderable($endpoint, $subsidiary, $planCode)) {
            return self::fail($serviceId, 'This VPS plan is currently out of stock at OVH; the order was not placed. It can be retried once stock returns.');
        }

        $client = OvhClient::fromParams($params);

        // Customer selections -> OVH config + options, with product defaults.
        // The chosen OS image (vps_os) carries its mandatory license addon (free
        // Linux or paid Windows), baked in by ConfigOptions::generate(); n8n/Docker
        // variants are just OS images, so the image choice drives both.
        $selection = ConfigOptions::parse($params);
        $config = self::withDefaults($selection['config'], $cfg);
        $options = $selection['options'];

        // Don't order into a datacenter that's out of stock for the chosen OS.
        $chosenDc = self::pickValue($config, 'vps_datacenter');
        $chosenOs = self::pickValue($config, 'vps_os');
        if ($chosenDc !== null && !Availability::isDatacenterOrderable($endpoint, $subsidiary, $planCode, $chosenDc, $chosenOs)) {
            return self::fail($serviceId, 'The selected datacenter (' . $chosenDc . ') is out of stock for the chosen operating system; the order was not placed.');
        }

        self::record($serviceId, [
            'status' => 'pending',
            'request_json' => json_encode(['planCode' => $planCode, 'config' => $config, 'options' => $options]),
        ]);

        try {
            // Snapshot existing VPS so we can diff for the new serviceName later.
            $before = self::listVpsNames($client);

            // 1) cart
            $cart = (array) $client->post('/order/cart', [
                'ovhSubsidiary' => $subsidiary,
                'description' => 'WHMCS service ' . $serviceId,
            ]);
            $cartId = (string) ($cart['cartId'] ?? '');
            if ($cartId === '') {
                return self::fail($serviceId, 'OVH did not return a cartId.');
            }
            self::record($serviceId, ['cart_id' => $cartId, 'status' => 'cart']);

            // 2) assign (required before checkout)
            $client->post('/order/cart/' . $cartId . '/assign');

            // 3) add the VPS
            $item = (array) $client->post('/order/cart/' . $cartId . '/vps', [
                'planCode' => $planCode,
                'duration' => $duration,
                'pricingMode' => $pricingMode,
                'quantity' => 1,
            ]);
            $itemId = (string) ($item['itemId'] ?? '');
            if ($itemId === '') {
                return self::fail($serviceId, 'OVH did not return an itemId for the VPS.');
            }
            self::record($serviceId, ['item_id' => $itemId, 'status' => 'item']);

            // 4) read required configuration (labels vary by generation)
            $required = (array) $client->get(
                '/order/cart/' . $cartId . '/item/' . $itemId . '/requiredConfiguration'
            );
            $requiredLabels = array_values(array_filter(array_map(
                static fn ($r) => is_array($r) ? ($r['label'] ?? null) : null,
                $required
            )));

            // 5) set each config item, one POST per label
            foreach ($config as $entry) {
                $label = self::resolveLabel($entry['label'], $requiredLabels);
                if ($label === null) {
                    continue;
                }
                $client->post('/order/cart/' . $cartId . '/item/' . $itemId . '/configuration', [
                    'label' => $label,
                    'value' => $entry['value'],
                ]);
            }

            // 6) add options (backup, snapshot, disk, ip, veeam...), each matched
            // to the same billing term where the addon supports it; an addon that
            // only sells monthly falls back to the VPS's resolved term.
            foreach ($options as $opt) {
                $optResolved = $months !== null
                    ? Term::pickPricing(Catalog::getOptionPricings($endpoint, $subsidiary, $planCode, (string) $opt['planCode']), $months)
                    : null;
                $client->post('/order/cart/' . $cartId . '/vps/options', [
                    'itemId' => (int) $itemId,
                    'planCode' => $opt['planCode'],
                    'duration' => $optResolved['duration'] ?? $duration,
                    'pricingMode' => $optResolved['pricingMode'] ?? $pricingMode,
                    'quantity' => max(1, (int) $opt['quantity']),
                ]);
            }

            // 7) dry-run checkout -> capture expected cost (margin auditing)
            $dryRun = (array) $client->get('/order/cart/' . $cartId . '/checkout');
            [$cost, $currency] = self::extractPrice($dryRun);
            self::record($serviceId, [
                'status' => 'priced',
                'expected_cost' => $cost,
                'currency' => $currency,
                'response_json' => json_encode($dryRun),
            ]);

            // 8) real checkout (auto-pay with the OVH account's preferred method)
            $checkout = (array) $client->post('/order/cart/' . $cartId . '/checkout', [
                'autoPayWithPreferredPaymentMethod' => true,
                'waiveRetractationPeriod' => true,
            ]);
            $orderId = (string) ($checkout['orderId'] ?? '');
            self::record($serviceId, [
                'order_id' => $orderId !== '' ? $orderId : null,
                'status' => $orderId !== '' ? 'checking' : 'failed',
            ]);
            if ($orderId === '') {
                return self::fail($serviceId, 'Checkout did not return an orderId.');
            }

            // 9) resolve serviceName (bounded poll; cron finishes if not ready)
            $serviceName = self::resolveServiceName($client, $orderId, $before);

            Database::upsertServer($serviceId, [
                'service_name' => $serviceName,
                'order_id' => $orderId,
                'endpoint' => $endpoint,
                'subsidiary' => $subsidiary,
                'plan_code' => $planCode,
                'datacenter' => self::pickValue($config, 'vps_datacenter'),
                'os' => self::pickValue($config, 'vps_os'),
                'options_json' => json_encode($options),
                'state' => $serviceName ? 'delivered' : 'provisioning',
            ]);

            if ($serviceName === null) {
                self::record($serviceId, ['status' => 'checking']);
                return [
                    'success' => true,
                    'serviceName' => null,
                    'status' => 'provisioning',
                    'message' => 'Order ' . $orderId . ' placed; VPS still being delivered by OVH (will finish via cron).',
                ];
            }

            // serviceName is known here: guarantee auto-renew so committed
            // multi-year terms survive. Non-fatal if it fails (logged).
            try {
                Lifecycle::ensureAutoRenew($client, $serviceName);
            } catch (\Throwable $e) {
                Helper::log('ensureAutoRenew', ['service' => $serviceName], $e->getMessage(), false, $serviceId);
            }

            self::record($serviceId, ['status' => 'delivered']);
            return [
                'success' => true,
                'serviceName' => $serviceName,
                'status' => 'delivered',
                'message' => 'VPS ' . $serviceName . ' provisioned.',
            ];
        } catch (\Throwable $e) {
            $hint = self::hint($e);
            self::record($serviceId, ['status' => 'failed', 'response_json' => json_encode(['error' => $e->getMessage()])]);
            return self::fail($serviceId, $e->getMessage() . $hint);
        }
    }

    /**
     * Poll for the newly delivered serviceName: prefer the /vps inventory diff,
     * fall back to order status. Returns null if not ready within the window.
     *
     * @param list<string> $before serviceNames that existed before ordering
     */
    public static function resolveServiceName(OvhClient $client, string $orderId, array $before, int $attempts = 6, int $sleepSeconds = 8): ?string
    {
        $beforeSet = array_flip($before);
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $now = self::listVpsNames($client);
                foreach ($now as $name) {
                    if (!isset($beforeSet[$name])) {
                        return $name;
                    }
                }
            } catch (\Throwable $e) {
                // ignore and retry
            }
            if ($i < $attempts - 1) {
                sleep($sleepSeconds);
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function listVpsNames(OvhClient $client): array
    {
        $list = $client->get('/vps');
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * Apply product default OS/datacenter when the customer did not pick one.
     *
     * @param list<array{label:string,value:string}> $config
     * @param array<string,string> $cfg
     * @return list<array{label:string,value:string}>
     */
    private static function withDefaults(array $config, array $cfg): array
    {
        $have = array_column($config, 'label');
        if (!in_array('vps_os', $have, true) && trim($cfg['default_os']) !== '') {
            $config[] = ['label' => 'vps_os', 'value' => trim($cfg['default_os'])];
        }
        if (!in_array('vps_datacenter', $have, true) && trim($cfg['default_datacenter']) !== '') {
            $config[] = ['label' => 'vps_datacenter', 'value' => trim($cfg['default_datacenter'])];
        }
        return $config;
    }

    /**
     * Match our intended label against what the cart actually requires
     * (handles vps_os/vps_datacenter vs legacy vps_ssd_os/vps_ssd_datacenter).
     *
     * @param list<string> $requiredLabels
     */
    private static function resolveLabel(string $intended, array $requiredLabels): ?string
    {
        if (in_array($intended, $requiredLabels, true)) {
            return $intended;
        }
        $needle = str_contains($intended, 'datacenter') ? 'datacenter' : 'os';
        foreach ($requiredLabels as $label) {
            if (str_contains(strtolower($label), $needle)) {
                return $label;
            }
        }
        // No required label matched; send as-is (OVH will validate).
        return $requiredLabels ? null : $intended;
    }

    /**
     * @param list<array{label:string,value:string}> $config
     */
    private static function pickValue(array $config, string $label): ?string
    {
        foreach ($config as $entry) {
            if ($entry['label'] === $label) {
                return $entry['value'];
            }
        }
        return null;
    }

    /**
     * Pull a representative price out of the dry-run checkout payload.
     *
     * @param array<string, mixed> $checkout
     * @return array{0: float|null, 1: string|null}
     */
    public static function extractPrice(array $checkout): array
    {
        $prices = $checkout['prices'] ?? null;
        if (is_array($prices)) {
            // Object form: prices.withTax.value / prices.withoutTax.value
            foreach (['withTax', 'withoutTax'] as $k) {
                if (isset($prices[$k]['value'])) {
                    return [(float) $prices[$k]['value'], $prices[$k]['currencyCode'] ?? null];
                }
            }
            // List form: sum of line values.
            $sum = 0.0;
            $currency = null;
            foreach ($prices as $line) {
                if (isset($line['price']['value'])) {
                    $sum += (float) $line['price']['value'];
                    $currency = $line['price']['currencyCode'] ?? $currency;
                }
            }
            if ($sum > 0) {
                return [$sum, $currency];
            }
        }
        return [null, null];
    }

    private static function hint(\Throwable $e): string
    {
        $code = $e->getCode();
        if ($code === 403) {
            return ' [403: check the consumer key has POST /order/* rights, that the OVH account has a default payment method, and that the subsidiary matches the account country.]';
        }
        return '';
    }

    private static function fail(int $serviceId, string $message): array
    {
        Helper::log('provision:fail', ['service_id' => $serviceId], $message, false, $serviceId);
        return ['success' => false, 'serviceName' => null, 'status' => 'failed', 'message' => $message];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getOrder(int $serviceId): ?array
    {
        $row = Capsule::table(Database::ORDERS)->where('service_id', $serviceId)->orderBy('id', 'desc')->first();
        return $row ? (array) $row : null;
    }

    /**
     * Upsert the single order row for a service.
     *
     * @param array<string,mixed> $data
     */
    private static function record(int $serviceId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $exists = Capsule::table(Database::ORDERS)->where('service_id', $serviceId)->exists();
        if ($exists) {
            Capsule::table(Database::ORDERS)->where('service_id', $serviceId)->update($data);
            return;
        }
        $data['service_id'] = $serviceId;
        $data['created_at'] = date('Y-m-d H:i:s');
        Capsule::table(Database::ORDERS)->insert($data);
    }
}
