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

        // Access bootstrap pass: drive freshly delivered plain VPS through key
        // install -> console password so the customer can log in. Only services
        // explicitly marked at provisioning ('none'/'key_installed') are touched;
        // pre-Phase-B services (NULL access_state) are never auto-bootstrapped, so
        // an in-use VPS is never rebuilt. n8n short-circuits to the 'web' state.
        $pendingAccess = Capsule::table(Database::SERVERS)
            ->whereNotNull('service_name')
            ->whereIn('access_state', ['none', 'key_installed'])
            ->get();
        foreach ($pendingAccess as $row) {
            $serviceId = (int) $row->service_id;
            $params = Helper::paramsForService($serviceId);
            if ($params === null) {
                continue;
            }
            try {
                self::bootstrapAccess($serviceId, $params, (array) $row);
            } catch (\Throwable $e) {
                Helper::log('cron:access', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
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

    /**
     * Drive one service through the access bootstrap state machine:
     * none -> key_installed -> ready (n8n short-circuits to 'web'). Every step is
     * guarded so a re-run never re-installs a VPS already in use.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $row    mod_ovhvps_servers row (cast to array)
     */
    private static function bootstrapAccess(int $serviceId, array $params, array $row): void
    {
        $serviceName = (string) ($row['service_name'] ?? '');
        if ($serviceName === '') {
            return;
        }
        $os = (string) ($row['os'] ?? '');
        $client = OvhClient::fromParams($params);

        // n8n is web-only: no root bootstrap. Once OVH assigns the IP, mark it web
        // and email the customer the n8n URL (create the owner account on first
        // visit). Stays pending until the IP exists.
        if (stripos($os, 'n8n') !== false) {
            $ip = self::mainIp($client, $serviceName);
            if ($ip === '') {
                return; // IP not assigned yet; retry on the next cron tick.
            }
            Database::upsertServer($serviceId, ['ip_main' => $ip, 'access_state' => 'web']);
            self::notifyN8n($serviceId, $ip);
            return;
        }

        $state = (string) ($row['access_state'] ?? '');

        // Step A: install our key with one destructive rebuild, exactly once.
        if ($state === 'none') {
            $pair = AccessBootstrap::generateKeyPair();
            $imageId = self::imageIdForOs($client, $serviceName, $os);
            AccessBootstrap::installKey($client, $serviceName, $imageId, $pair['public']);
            Database::upsertServer($serviceId, [
                'ssh_pubkey' => $pair['public'],
                'ssh_privkey_enc' => Helper::encrypt($pair['private']),
                'access_state' => 'key_installed',
            ]);
            Helper::log('cron:access', ['service_id' => $serviceId], 'key installed; awaiting reinstall', true, $serviceId);
            return; // the SSH step runs on a later tick once the VPS is up
        }

        // Step B: set the console password over SSH, then mark ready + notify.
        if ($state === 'key_installed') {
            $ip = self::mainIp($client, $serviceName);
            $user = AccessBootstrap::defaultUser($os);
            $priv = Helper::decrypt((string) ($row['ssh_privkey_enc'] ?? ''));
            $pass = AccessBootstrap::generatePassword();
            if ($ip !== '' && $priv !== '' && AccessBootstrap::setPassword($ip, $user, $priv, $pass)) {
                Database::upsertServer($serviceId, [
                    'root_user' => $user,
                    'ip_main' => $ip,
                    'access_state' => 'ready',
                ]);
                self::notifyReady($serviceId, $pass);
                Helper::log('cron:access', ['service_id' => $serviceId], ['ready' => true, 'user' => $user], true, $serviceId);
            }
            // else: stay key_installed; the next tick retries (VPS still booting).
        }
    }

    /**
     * Match the ordered OS name to an available image id for the rebuild. Throws
     * when none matches so the caller logs and retries on the next tick.
     */
    private static function imageIdForOs(OvhClient $client, string $serviceName, string $os): string
    {
        $os = trim($os);
        $ids = $client->get('/vps/' . $serviceName . '/images/available');
        if (is_array($ids) && $os !== '') {
            foreach ($ids as $id) {
                $detail = $client->get('/vps/' . $serviceName . '/images/available/' . rawurlencode((string) $id));
                $name = is_array($detail) ? (string) ($detail['name'] ?? '') : '';
                if ($name !== '' && stripos($name, $os) !== false) {
                    return (string) ($detail['id'] ?? $id);
                }
            }
        }
        throw new \RuntimeException('No matching OVH image id for OS "' . $os . '"');
    }

    /** First IPv4 of the VPS, or '' if none is assigned yet. */
    private static function mainIp(OvhClient $client, string $serviceName): string
    {
        $ips = $client->get('/vps/' . $serviceName . '/ips');
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (filter_var((string) $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return (string) $ip;
                }
            }
        }
        return '';
    }

    /**
     * Mirror the non-secret fields (username, IP) into the WHMCS service so the
     * admin sees them, then email the access details with the password injected
     * at send time (never stored). The password is passed in, not read back.
     */
    private static function notifyReady(int $serviceId, string $password): void
    {
        $server = Database::getServer($serviceId) ?: [];
        try {
            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'username' => (string) ($server['root_user'] ?? ''),
                'dedicatedip' => (string) ($server['ip_main'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Helper::log('cron:notify', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
        }

        AccessMail::sendAccessReady($serviceId, $password);
        Helper::log('cron:notify', ['service_id' => $serviceId], ['emailed' => true], true, $serviceId);
    }

    /**
     * Write the IP into the WHMCS service and send the n8n access email (URL +
     * create-account note). n8n is web-only, so there is no password.
     */
    private static function notifyN8n(int $serviceId, string $ip): void
    {
        try {
            Capsule::table('tblhosting')->where('id', $serviceId)->update(['dedicatedip' => $ip]);
        } catch (\Throwable $e) {
            Helper::log('cron:notify', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
        }
        if (function_exists('localAPI')) {
            localAPI('SendEmail', ['messagename' => Database::EMAIL_TEMPLATE_N8N, 'id' => $serviceId]);
        }
        Helper::log('cron:notify', ['service_id' => $serviceId], ['n8n' => true], true, $serviceId);
    }
}
