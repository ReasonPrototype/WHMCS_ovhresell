<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Background reconciliation, run from the WHMCS cron (AfterCronJob hook).
 *
 * Finishes provisioning for orders whose serviceName was not yet available at
 * checkout time: it lists the OVH VPS inventory, finds VPS not yet mapped to any
 * WHMCS service, and assigns them to the oldest pending orders.
 */
class Cron
{
    /**
     * @return array{resolved:int, pending:int}
     */
    public static function run(): array
    {
        Helper::init();

        $checking = Capsule::table(Database::ORDERS)
            ->where('status', 'checking')
            ->orderBy('id')
            ->get();

        $resolved = 0;
        $stillPending = 0;

        foreach ($checking as $order) {
            $serviceId = (int) $order->service_id;
            $server = Database::getServer($serviceId);
            if ($server && !empty($server['service_name'])) {
                Capsule::table(Database::ORDERS)->where('id', $order->id)->update(['status' => 'delivered']);
                continue;
            }

            $params = Helper::paramsForService($serviceId);
            if ($params === null) {
                continue;
            }

            try {
                $client = OvhClient::fromParams($params);
                // Deterministic: resolve the serviceName for THIS order.
                $orderId = (string) ($order->order_id ?? '');
                $name = Provisioning::serviceNameFromOrder($client, $orderId);
                if ($name === null) {
                    // Fallback: a single unambiguous new VPS vs the before-snapshot.
                    $before = (array) json_decode((string) ($order->vps_before_json ?? '[]'), true);
                    $name = self::resolveByDiff($client, $before);
                }
                if ($name === null) {
                    $stillPending++;
                    continue;
                }

                Database::upsertServer($serviceId, [
                    'service_name' => $name,
                    'state' => 'delivered',
                ]);
                Capsule::table(Database::ORDERS)->where('id', $order->id)->update([
                    'status' => 'delivered',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                // This is the async multi-year path: now that the serviceName is
                // known, guarantee auto-renew. Non-fatal if it fails (logged).
                try {
                    Lifecycle::ensureAutoRenew($client, $name);
                } catch (\Throwable $e) {
                    Helper::log('ensureAutoRenew', ['service' => $name], $e->getMessage(), false, $serviceId);
                }
                Helper::log('cron:resolve', ['service_id' => $serviceId], ['service_name' => $name], true, $serviceId);
                $resolved++;
            } catch (\Throwable $e) {
                Helper::log('cron:resolve', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
                $stillPending++;
            }
        }

        // Refresh OVH stock -> WHMCS product availability (throttled to hourly).
        try {
            Availability::refreshIfDue();
        } catch (\Throwable $e) {
            Helper::log('cron:availability', null, $e->getMessage(), false);
        }

        return ['resolved' => $resolved, 'pending' => $stillPending];
    }

    /**
     * Diff live /vps against a before-snapshot and return the single new VPS not
     * already mapped to any service. Returns null on zero or ambiguous (>1)
     * candidates, so the cron never guesses a wrong mapping when several orders
     * are delivering at once.
     *
     * @param list<string> $before serviceNames that existed before the order
     */
    private static function resolveByDiff(OvhClient $client, array $before): ?string
    {
        $all = $client->get('/vps');
        if (!is_array($all)) {
            return null;
        }
        $beforeSet = array_flip(array_map('strval', $before));
        $mapped = array_flip(array_map('strval', Capsule::table(Database::SERVERS)
            ->whereNotNull('service_name')
            ->pluck('service_name')
            ->all()));

        $candidates = [];
        foreach ($all as $name) {
            $name = (string) $name;
            if (!isset($beforeSet[$name]) && !isset($mapped[$name])) {
                $candidates[] = $name;
            }
        }
        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
