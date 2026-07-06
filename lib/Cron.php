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
            // Upgrade-plan orders are closed by the upgrade-completion pass below
            // (after the resize settles + the email goes out), not here. Skip them
            // so this create-resolution loop does not mark them delivered early and
            // swallow them before the upgrade email can fire.
            if (($order->kind ?? '') === 'upgrade-plan') {
                continue;
            }
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

        // Access bootstrap pass: drive freshly delivered VPS through key
        // install -> console password so the customer can log in (Linux images,
        // n8n included); Windows images branch to the manual-delivery path
        // inside bootstrapAccess. Only services explicitly marked at
        // provisioning ('none'/'key_installed') are touched; pre-Phase-B
        // services (NULL access_state) are never auto-bootstrapped, so an
        // in-use VPS is never rebuilt.
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

        // Model-upgrade completion: an 'upgrade-plan' order sits at status
        // 'checking' from Upgrade::plan. When the resize+reboot has settled (the
        // VPS is 'running' again), email the customer once and close the order.
        $pendingUpgrades = Capsule::table(Database::ORDERS)
            ->where('kind', 'upgrade-plan')
            ->where('status', 'checking')
            ->get();
        foreach ($pendingUpgrades as $up) {
            $serviceId = (int) $up->service_id;
            $params = Helper::paramsForService($serviceId);
            if ($params === null) {
                continue;
            }
            try {
                $server = Database::getServer($serviceId) ?? [];
                $serviceName = (string) ($server['service_name'] ?? '');
                if ($serviceName === '') {
                    continue;
                }
                $vps = (array) OvhClient::fromParams($params)->get('/vps/' . $serviceName);
                if ((string) ($vps['state'] ?? '') !== 'running') {
                    continue; // resize still in progress; retry next tick
                }
                Database::upsertServer($serviceId, ['state' => 'running']);
                Capsule::table(Database::ORDERS)->where('id', $up->id)->update([
                    'status' => 'delivered',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                AccessMail::sendUpgradeComplete($serviceId);
                Helper::log('cron:upgrade', ['service_id' => $serviceId], ['emailed' => true], true, $serviceId);
            } catch (\Throwable $e) {
                Helper::log('cron:upgrade', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
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
     * none -> key_installed -> ready. Every Linux image family (plain distro,
     * Docker, n8n) runs the same bootstrap, so every customer gets root/console
     * access; the app on an application image survives because the rebuild
     * reinstalls the SAME image. Every step is guarded so a re-run never
     * re-installs a VPS already in use.
     *
     * Windows never enters this state machine: there is no SSH to bootstrap
     * and the rebuild-with-key would wipe the OVH-installed licensed Windows.
     * It takes the manual-delivery path instead (terminal state 'manual').
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

        // Windows path: no key install (never rebuild what OVH just licensed
        // and installed), no SSH password step. Wait for the IPv4, mirror it,
        // park the service in the terminal 'manual' state, and hand credential
        // delivery to the admin. Catches BOTH 'none' and 'key_installed', so a
        // Windows row stuck from before this path existed is rescued too.
        if (AccessBootstrap::needsManualAccess($os)) {
            self::bootstrapManualAccess($serviceId, $client, $serviceName, $params, $os);
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
            $info = Actions::info($client, $serviceName);
            $ip = (string) ($info['ip'] ?? '');
            $ipv6 = (string) ($info['ipv6'] ?? '');
            $user = AccessBootstrap::defaultUser($os);
            $priv = Helper::decrypt((string) ($row['ssh_privkey_enc'] ?? ''));
            $pass = AccessBootstrap::generatePassword();
            if ($ip !== '' && $priv !== '' && AccessBootstrap::setPassword($ip, $user, $priv, $pass)) {
                Database::upsertServer($serviceId, [
                    'root_user' => $user,
                    'ip_main' => $ip,
                    'access_state' => 'ready',
                ]);
                self::notifyReady($serviceId, $pass, $os, $ip, $ipv6, $user);
                Helper::log('cron:access', ['service_id' => $serviceId], ['ready' => true, 'user' => $user], true, $serviceId);
            }
            // else: stay key_installed; the next tick retries (VPS still booting).
        }
    }

    /**
     * Windows delivery: once OVH reports the IPv4, mirror it into the WHMCS
     * service, park the row in the terminal 'manual' access state (the cron
     * only re-enters 'none'/'key_installed', so this stops the retry loop),
     * notify the admins that credentials must be delivered by hand (OVH sent
     * the Windows password to the OVH account owner, not the customer), and
     * send the customer a credential-free Windows heads-up email.
     *
     * @param array<string,mixed> $params
     */
    private static function bootstrapManualAccess(int $serviceId, OvhClient $client, string $serviceName, array $params, string $os): void
    {
        $info = Actions::info($client, $serviceName);
        $ip = (string) ($info['ip'] ?? '');
        if ($ip === '') {
            return; // OVH is still installing; the next tick retries.
        }

        try {
            Capsule::table('tblhosting')->where('id', $serviceId)->update(['dedicatedip' => $ip]);
        } catch (\Throwable $e) {
            Helper::log('cron:access', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
        }

        // Terminal state FIRST: if an email fails it is logged (send() never
        // throws), but the service must never loop back into the SSH bootstrap.
        Database::upsertServer($serviceId, [
            'ip_main' => $ip,
            'access_state' => 'manual',
        ]);

        $domain = (string) ($params['domain'] ?? '');
        AccessMail::sendAdminManualAccess($serviceId, $domain, $os, $ip);
        AccessMail::sendWindowsReady($serviceId);
        Helper::log('cron:access', ['service_id' => $serviceId], ['manual' => true, 'os' => $os, 'ip' => $ip], true, $serviceId);
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

    /**
     * Mirror the non-secret fields (username, IP) into the WHMCS service so the
     * admin sees them, then email the access details with the password and the
     * (non-secret) access fields injected at send time (never stored).
     */
    private static function notifyReady(int $serviceId, string $password, string $os, string $ipv4, string $ipv6, string $sshUser): void
    {
        try {
            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'username' => $sshUser,
                'dedicatedip' => $ipv4,
            ]);
        } catch (\Throwable $e) {
            Helper::log('cron:notify', ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
        }

        AccessMail::sendAccessReady($serviceId, $password, [
            'os' => $os,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'ssh_user' => $sshUser,
            'service_url' => self::serviceUrl($serviceId),
        ]);

        // An application image is a normal Linux VPS plus the app: on top of
        // the root-access email, send the app-specific heads-up (the n8n
        // editor URL, or the Docker quick-start) so the customer knows how to
        // reach what they installed. Covers both initial delivery and EVERY
        // reinstall into the image, because each reinstall re-enters the
        // bootstrap state machine and ends up here again.
        $family = ConfigOptions::imageFamily($os);
        if ($family === 'n8n') {
            AccessMail::sendN8nReady($serviceId);
        } elseif ($family === 'docker') {
            AccessMail::sendDockerReady($serviceId);
        }
        Helper::log('cron:notify', ['service_id' => $serviceId], ['emailed' => true, 'family' => $family], true, $serviceId);
    }

    /**
     * The customer's WHMCS service-page URL (where the Console tab lives), built
     * from the configured System URL. Empty when the System URL is unknown.
     */
    private static function serviceUrl(int $serviceId): string
    {
        $base = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
        return $base === '' ? '' : ($base . '/clientarea.php?action=productdetails&id=' . $serviceId);
    }

}
