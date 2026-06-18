<?php

namespace OvhVps;

/**
 * Central dispatcher for VPS management actions used by both the client area
 * and the admin service panel. Every handler returns a normalised envelope:
 *
 *   ['status' => 'OK'|'Error'|'Processing', 'message' => string, 'data' => mixed]
 *
 * F5 covers power, console, OS reinstall, snapshots and rescue. F7 extends the
 * switch with backups, Veeam, disks, IPs/reverse DNS, Backup FTP, secondary DNS,
 * upgrade and graphs.
 */
class Actions
{
    /**
     * @param array<string, mixed> $params Server-module style params (with creds).
     * @param array<string, mixed> $input  Request input (action arguments).
     * @return array{status:string, message:string, data:mixed}
     */
    public static function dispatch(string $action, array $params, array $input = []): array
    {
        $serviceName = Lifecycle::serviceName($params);
        if ($serviceName === null) {
            return self::err('This service has no VPS yet (still provisioning?).');
        }

        try {
            $client = OvhClient::fromParams($params);
            switch ($action) {
                case 'info':
                    return self::ok('', self::info($client, $serviceName));
                case 'n8n_status':
                    // vps-n8n ships n8n pre-installed in the OVH OS image; show
                    // the access endpoint (n8n listens on port 5678 by default).
                    $vps = self::info($client, $serviceName);
                    $ip = $vps['ip'] ?? '';
                    return self::ok('', [
                        'ip' => $ip,
                        'state' => $vps['state'] ?? '',
                        'url' => $ip !== '' ? ('http://' . $ip . ':5678') : '',
                    ]);
                case 'reboot':
                case 'start':
                case 'stop':
                    $client->post('/vps/' . $serviceName . '/' . $action);
                    return self::ok(ucfirst($action) . ' requested.');
                case 'console':
                    return self::ok('', ['url' => self::consoleUrl($client, $serviceName)]);
                case 'images':
                    return self::ok('', self::images($client, $serviceName, $params));
                case 'reinstall':
                    return self::reinstall($client, $serviceName, $params, $input);
                case 'reinstall_n8n':
                    return self::reinstallN8n($client, $serviceName, $params);
                case 'snapshot_list':
                    return self::ok('', self::snapshotInfo($client, $serviceName));
                case 'snapshot_create':
                    $client->post('/vps/' . $serviceName . '/createSnapshot', array_filter([
                        'description' => (string) ($input['description'] ?? ''),
                    ]));
                    return self::processing('Snapshot creation started.');
                case 'snapshot_revert':
                    $client->post('/vps/' . $serviceName . '/snapshot/revert');
                    return self::processing('Reverting to snapshot.');
                case 'snapshot_delete':
                    $client->delete('/vps/' . $serviceName . '/snapshot');
                    return self::ok('Snapshot deleted.');
                case 'rescue_on':
                    return self::setNetboot($client, $serviceName, 'rescue');
                case 'rescue_off':
                    return self::setNetboot($client, $serviceName, 'local');
                case 'task':
                    $taskId = (string) ($input['task_id'] ?? '');
                    return self::ok('', $client->get('/vps/' . $serviceName . '/tasks/' . $taskId));

                // --- F7: full parity ---
                case 'backup_status':
                    return self::ok('', [
                        'backup' => self::safeGet($client, '/vps/' . $serviceName . '/automatedBackup'),
                        'restorePoints' => self::safeGet($client, '/vps/' . $serviceName . '/automatedBackup/restorePoints'),
                    ]);
                case 'backup_restore':
                    $client->post('/vps/' . $serviceName . '/automatedBackup/restore', array_filter([
                        'restorePoint' => (string) ($input['restore_point'] ?? ''),
                        'changePassword' => !empty($input['change_password']),
                    ], static fn ($v) => $v !== '' && $v !== false));
                    return self::processing('Restoring from automated backup.');
                case 'veeam_status':
                    return self::ok('', [
                        'veeam' => self::safeGet($client, '/vps/' . $serviceName . '/veeam'),
                        'restorePoints' => self::safeGet($client, '/vps/' . $serviceName . '/veeam/restorePoints'),
                    ]);
                case 'veeam_restore':
                    $rpId = (string) ($input['restore_point_id'] ?? '');
                    $client->post('/vps/' . $serviceName . '/veeam/restorePoints/' . $rpId . '/restore');
                    return self::processing('Restoring from Veeam restore point.');
                case 'disks_list':
                    return self::ok('', self::disks($client, $serviceName));
                case 'ips_list':
                    return self::ok('', self::ips($client, $serviceName));
                case 'reverse_set':
                    $ip = (string) ($input['ip'] ?? '');
                    $client->put('/vps/' . $serviceName . '/ips/' . rawurlencode($ip), [
                        'reverse' => (string) ($input['reverse'] ?? ''),
                    ]);
                    return self::ok('Reverse DNS updated.');
                case 'ftp_status':
                    return self::ok('', self::safeGet($client, '/vps/' . $serviceName . '/backupftp'));
                case 'dns_list':
                    return self::ok('', self::safeGet($client, '/vps/' . $serviceName . '/secondaryDnsDomains'));
                case 'dns_add':
                    $client->post('/vps/' . $serviceName . '/secondaryDnsDomains', array_filter([
                        'domain' => (string) ($input['domain'] ?? ''),
                        'ip' => (string) ($input['ip'] ?? ''),
                    ]));
                    return self::ok('Secondary DNS domain added.');
                case 'dns_remove':
                    $client->delete('/vps/' . $serviceName . '/secondaryDnsDomains/' . rawurlencode((string) ($input['domain'] ?? '')));
                    return self::ok('Secondary DNS domain removed.');
                case 'upgrade_list':
                    return self::ok('', [
                        'upgrades' => self::safeGet($client, '/vps/' . $serviceName . '/availableUpgrade'),
                        'models' => self::safeGet($client, '/vps/' . $serviceName . '/models'),
                    ]);
                case 'graphs':
                    // /use and /monitoring are deprecated; /statistics is the
                    // current endpoint. Best-effort: pass through only the params
                    // the caller supplied (exact contract confirmed on live data).
                    return self::ok('', [
                        'statistics' => self::safeGet($client, '/vps/' . $serviceName . '/statistics', array_filter([
                            'period' => (string) ($input['period'] ?? ''),
                            'type' => (string) ($input['type'] ?? ''),
                        ], static fn ($v) => $v !== '')),
                    ]);

                default:
                    return self::err('Unknown action: ' . $action);
            }
        } catch (\Throwable $e) {
            Helper::log('action:' . $action, ['service' => $serviceName, 'input' => $input], $e->getMessage(), false, (int) ($params['serviceid'] ?? 0));
            return self::err($e->getMessage());
        }
    }

    /**
     * Normalised VPS info for the overview panel.
     *
     * @return array<string, mixed>
     */
    public static function info(OvhClient $client, string $serviceName): array
    {
        $vps = (array) $client->get('/vps/' . $serviceName);
        $model = $vps['model'] ?? [];
        $ips = $client->get('/vps/' . $serviceName . '/ips');
        // /ips is a flat list of IP strings; split the first v4 and v6 out.
        $ipv4 = '';
        $ipv6 = '';
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                $ip = (string) $ip;
                if ($ipv6 === '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ipv6 = $ip;
                } elseif ($ipv4 === '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipv4 = $ip;
                }
            }
        }

        return [
            'name' => $vps['name'] ?? $serviceName,
            'displayName' => $vps['displayName'] ?? ($vps['name'] ?? $serviceName),
            'state' => $vps['state'] ?? 'unknown',
            'zone' => $vps['zone'] ?? ($vps['datacenter'] ?? ''),
            'memoryLimit' => isset($model['memory']) ? round(((int) $model['memory']) / 1024) : ($model['ram'] ?? ''),
            'disk' => $model['disk'] ?? '',
            'vcore' => $model['vcore'] ?? ($model['maximumVcoreCount'] ?? ''),
            'ip' => $ipv4,
            'ipv6' => $ipv6,
            'netbootMode' => $vps['netbootMode'] ?? '',
        ];
    }

    private static function consoleUrl(OvhClient $client, string $serviceName): string
    {
        // Returns an HTTPS noVNC URL. openConsoleAccess primes a fresh session.
        try {
            $client->post('/vps/' . $serviceName . '/openConsoleAccess');
        } catch (\Throwable $e) {
            // Not all ranges expose openConsoleAccess; getConsoleUrl still works.
        }
        $url = $client->post('/vps/' . $serviceName . '/getConsoleUrl');
        return is_string($url) ? $url : (string) ($url['url'] ?? '');
    }

    /**
     * @return array{available: list<array{id:string,name:string}>, current: mixed}
     */
    private static function images(OvhClient $client, string $serviceName, array $params): array
    {
        return [
            'available' => self::allowedImages($client, $serviceName, $params),
            'current' => self::safeGet($client, '/vps/' . $serviceName . '/images/current'),
        ];
    }

    /**
     * Full OVH image catalogue for the VPS, expanded to {id,name}. /images/available
     * returns bare ids; we expand each so names show instead of UUIDs. Cached 24h
     * (the list is stable) to avoid N+1 detail calls on every Reinstall tab open.
     *
     * @return list<array{id:string,name:string}>
     */
    private static function availableImages(OvhClient $client, string $serviceName): array
    {
        $cacheKey = 'images:' . $serviceName;
        $available = Database::getCache($cacheKey, 86400);
        if (is_array($available)) {
            return $available;
        }
        $ids = $client->get('/vps/' . $serviceName . '/images/available');
        $available = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $detail = self::safeGet($client, '/vps/' . $serviceName . '/images/available/' . rawurlencode((string) $id));
                if (is_array($detail)) {
                    $available[] = [
                        'id' => (string) ($detail['id'] ?? $id),
                        'name' => (string) ($detail['name'] ?? $detail['distribution'] ?? $id),
                    ];
                } else {
                    $available[] = ['id' => (string) $id, 'name' => (string) $id];
                }
            }
        }
        Database::setCache($cacheKey, $available);
        return $available;
    }

    /**
     * The images a customer may reinstall to: the OS options the plan sells
     * (mod_ovhvps_option_map vps_os values) limited to the customer's entitled
     * family. A managed family (paid Windows/cPanel/Plesk, or an application image
     * like Docker/n8n) is offered only when the VPS was ordered with that same
     * family, so a customer can never reinstall into a license OVH would bill us
     * for, nor into an application image a plain VPS should not expose. Falls back
     * to the family filter alone when the plan OS names do not line up with the
     * image names, so reinstall always works but never leaks a managed image.
     *
     * @param array<string, mixed> $params
     * @return list<array{id:string,name:string}>
     */
    private static function allowedImages(OvhClient $client, string $serviceName, array $params): array
    {
        $all = self::availableImages($client, $serviceName);

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $server = $serviceId > 0 ? (Database::getServer($serviceId) ?? []) : [];
        $currentFamily = ConfigOptions::managedImageFamily((string) ($server['os'] ?? ''));

        // Family guard (every path): plain distributions are always allowed; a
        // managed family (paid Windows/cPanel/Plesk or an app image Docker/n8n) is
        // allowed only when the VPS was ordered with that same family.
        $familyOk = static function (array $img) use ($currentFamily): bool {
            $fam = ConfigOptions::managedImageFamily((string) ($img['name'] ?? ''));
            return $fam === '' || $fam === $currentFamily;
        };

        // Faithful list: only images whose name the plan offers as an OS option.
        $sold = [];
        foreach (Database::osOptionValues((int) ($params['pid'] ?? 0)) as $name) {
            $sold[ConfigOptions::normalizeOsName((string) $name)] = true;
        }
        if ($sold !== []) {
            $primary = array_values(array_filter($all, static function (array $img) use ($sold, $familyOk): bool {
                return isset($sold[ConfigOptions::normalizeOsName((string) ($img['name'] ?? ''))]) && $familyOk($img);
            }));
            if ($primary !== []) {
                return $primary;
            }
        }

        // Fallback: family filter only (still never leaks a managed image).
        return array_values(array_filter($all, $familyOk));
    }

    /**
     * Reinstall/rebuild the VPS with a chosen image.
     *
     * @param array<string, mixed> $input
     * @return array{status:string, message:string, data:mixed}
     */
    private static function reinstall(OvhClient $client, string $serviceName, array $params, array $input): array
    {
        // n8n products do not expose the full OS reinstall: switching to a plain
        // distro would destroy the n8n appliance. They use 'reinstall_n8n'.
        if (self::isN8nService($params)) {
            return self::err('Use the "Reinstall n8n" button for this service.');
        }
        $imageId = (string) ($input['image_id'] ?? '');
        if ($imageId === '') {
            return self::err('No image selected.');
        }
        // Server-side gate: the image must be in the plan's allowed list, so a
        // crafted request cannot reinstall to a managed image (paid or app image)
        // the customer is not entitled to.
        $images = self::allowedImages($client, $serviceName, $params);
        $allowedIds = array_map(static fn (array $img): string => (string) $img['id'], $images);
        if (!in_array($imageId, $allowedIds, true)) {
            return self::err('That operating system is not available for your plan.');
        }

        // Re-enter the access bootstrap so the customer gets fresh, emailed access
        // details exactly like a new order: rebuild with OUR management key (no OVH
        // password email), record it, and let the cron set + email the password.
        $osName = '';
        foreach ($images as $img) {
            if ((string) $img['id'] === $imageId) {
                $osName = (string) ($img['name'] ?? '');
                break;
            }
        }
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $pair = AccessBootstrap::generateKeyPair();
        AccessBootstrap::installKey($client, $serviceName, $imageId, $pair['public']);
        Database::upsertServer($serviceId, [
            'os' => $osName,
            'ssh_pubkey' => $pair['public'],
            'ssh_privkey_enc' => Helper::encrypt($pair['private']),
            'root_user' => null,
            'root_pass_enc' => null,
            'access_state' => 'key_installed',
        ]);
        return self::processing('Reinstall started. Your new access details will be emailed once the VPS is back up.');
    }

    /**
     * Wipe-and-reinstall an n8n service to a fresh n8n image: the sanctioned
     * "start from zero" path for an n8n product, which does not expose the full OS
     * reinstall. The target is always an n8n image (resolved from the ordered OS),
     * so the customer can never leave the n8n family here. n8n is web-only, so no
     * password email is sent (the owner account is recreated on the first visit).
     *
     * @param array<string, mixed> $params
     * @return array{status:string, message:string, data:mixed}
     */
    private static function reinstallN8n(OvhClient $client, string $serviceName, array $params): array
    {
        if (!self::isN8nService($params)) {
            return self::err('This action is only available for n8n services.');
        }
        // Reinstall the product's Default OS (the n8n image the product sells), so
        // the reset is independent of any OS configurable option: the admin sets it
        // once on the product and the button always rebuilds to it. Fall back to the
        // OS the VPS was ordered with when no Default OS is configured.
        $cfg = Helper::cfg($params);
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $server = $serviceId > 0 ? (Database::getServer($serviceId) ?? []) : [];
        $wantedOs = trim($cfg['default_os']) !== ''
            ? trim($cfg['default_os'])
            : (string) ($server['os'] ?? '');
        $imageId = ConfigOptions::pickImageId(self::availableImages($client, $serviceName), $wantedOs);
        if ($imageId === '') {
            return self::err('No n8n image is currently available for this VPS.');
        }
        $client->post('/vps/' . $serviceName . '/rebuild', [
            'imageId' => $imageId,
            'doNotSendPassword' => true,
        ]);
        return self::processing('Reinstalling n8n. All data will be wiped; n8n will be fresh in a few minutes.');
    }

    /**
     * Whether the service is an n8n product, decided (as everywhere in the module)
     * by the ordered OS image name containing "n8n".
     *
     * @param array<string, mixed> $params
     */
    private static function isN8nService(array $params): bool
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return false;
        }
        $server = Database::getServer($serviceId) ?? [];
        return stripos((string) ($server['os'] ?? ''), 'n8n') !== false;
    }

    /**
     * @return array{snapshot: mixed}
     */
    private static function snapshotInfo(OvhClient $client, string $serviceName): array
    {
        try {
            return ['snapshot' => $client->get('/vps/' . $serviceName . '/snapshot')];
        } catch (\Throwable $e) {
            // 404 when no snapshot exists.
            return ['snapshot' => null];
        }
    }

    /**
     * Toggle rescue/local netboot and reboot into it.
     *
     * @return array{status:string, message:string, data:mixed}
     */
    private static function setNetboot(OvhClient $client, string $serviceName, string $mode): array
    {
        $client->put('/vps/' . $serviceName, ['netbootMode' => $mode]);
        $client->post('/vps/' . $serviceName . '/reboot');
        return self::processing($mode === 'rescue' ? 'Rebooting into rescue mode.' : 'Rebooting into normal mode.');
    }

    /**
     * GET that returns null instead of throwing (e.g. 404 when a resource like a
     * backup or snapshot does not exist), so a panel can still render.
     *
     * @param array<string,mixed>|null $query
     * @return mixed
     */
    private static function safeGet(OvhClient $client, string $path, ?array $query = null)
    {
        try {
            return $client->get($path, $query);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function disks(OvhClient $client, string $serviceName): array
    {
        $ids = $client->get('/vps/' . $serviceName . '/disks');
        $out = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $detail = self::safeGet($client, '/vps/' . $serviceName . '/disks/' . rawurlencode((string) $id));
                $out[] = is_array($detail) ? $detail : ['id' => $id];
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function ips(OvhClient $client, string $serviceName): array
    {
        $list = $client->get('/vps/' . $serviceName . '/ips');
        $out = [];
        if (is_array($list)) {
            foreach ($list as $ip) {
                $detail = self::safeGet($client, '/vps/' . $serviceName . '/ips/' . rawurlencode((string) $ip));
                $out[] = is_array($detail) ? $detail : ['ipAddress' => $ip];
            }
        }
        return $out;
    }

    /**
     * @param mixed $data
     * @return array{status:string, message:string, data:mixed}
     */
    private static function ok(string $message = '', $data = null): array
    {
        return ['status' => 'OK', 'message' => $message, 'data' => $data];
    }

    /**
     * @return array{status:string, message:string, data:mixed}
     */
    private static function processing(string $message): array
    {
        return ['status' => 'Processing', 'message' => $message, 'data' => null];
    }

    /**
     * @return array{status:string, message:string, data:mixed}
     */
    private static function err(string $message): array
    {
        return ['status' => 'Error', 'message' => $message, 'data' => null];
    }
}
