<?php

namespace OvhVps;

/**
 * Central dispatcher for VPS management actions used by both the client area
 * and the admin service panel. Every handler returns a normalised envelope:
 *
 *   ['status' => 'OK'|'Error'|'Processing', 'message' => string, 'data' => mixed]
 *
 * F5 covers power, console, OS reinstall and snapshots. F7 extends the switch
 * with backups, Veeam, disks, IPs/reverse DNS, Backup FTP, secondary DNS,
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
            return self::err('msg_no_vps');
        }

        try {
            $client = OvhClient::fromParams($params);
            switch ($action) {
                case 'info':
                    return self::ok('', self::info($client, $serviceName));
                case 'n8n_status':
                    // The n8n image ships the editor on port 5678; expose the
                    // endpoint only when the installed image is n8n (mirrors
                    // the conditional client-area tab).
                    if (!self::isN8nService($params)) {
                        return self::err('msg_n8n_only');
                    }
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
                    return self::ok('msg_' . $action . '_requested');
                case 'console':
                    return self::ok('', ['url' => self::consoleUrl($client, $serviceName)]);
                case 'images':
                    return self::ok('', self::images($client, $serviceName, $params));
                case 'reinstall':
                    return self::reinstall($client, $serviceName, $params, $input);
                case 'snapshot_list':
                    return self::ok('', self::snapshotInfo($client, $serviceName));
                case 'snapshot_create':
                    $client->post('/vps/' . $serviceName . '/createSnapshot', array_filter([
                        'description' => (string) ($input['description'] ?? ''),
                    ]));
                    return self::processing('msg_snapshot_creating');
                case 'snapshot_revert':
                    $client->post('/vps/' . $serviceName . '/snapshot/revert');
                    return self::processing('msg_snapshot_reverting');
                case 'snapshot_delete':
                    $client->delete('/vps/' . $serviceName . '/snapshot');
                    return self::ok('msg_snapshot_deleted');
                case 'task':
                    $taskId = (string) ($input['task_id'] ?? '');
                    return self::ok('', $client->get('/vps/' . $serviceName . '/tasks/' . $taskId));

                // --- F7: full parity ---
                case 'backup_status':
                    return self::ok('', [
                        'backup' => self::safeGet($client, '/vps/' . $serviceName . '/automatedBackup'),
                        // OVH requires ?state=available to list the points that can
                        // actually be restored; without it the call 400s and the
                        // restore dropdown would stay empty.
                        'restorePoints' => self::safeGet($client, '/vps/' . $serviceName . '/automatedBackup/restorePoints', ['state' => 'available']),
                    ]);
                case 'backup_restore':
                    // OVH requires both restorePoint and type; "full" rolls the
                    // whole VPS back to the restore point (matches the client
                    // "Restore" confirmation). changePassword stays optional.
                    $client->post('/vps/' . $serviceName . '/automatedBackup/restore', [
                        'restorePoint' => (string) ($input['restore_point'] ?? ''),
                        'type' => 'full',
                        'changePassword' => !empty($input['change_password']),
                    ]);
                    return self::processing('msg_backup_restoring');
                case 'veeam_status':
                    return self::ok('', [
                        'veeam' => self::safeGet($client, '/vps/' . $serviceName . '/veeam'),
                        'restorePoints' => self::safeGet($client, '/vps/' . $serviceName . '/veeam/restorePoints'),
                    ]);
                case 'veeam_restore':
                    // OVH requires the "full" flag; true replaces the VPS with the
                    // chosen restore point (the roll-back the client button implies).
                    $rpId = (string) ($input['restore_point_id'] ?? '');
                    $client->post('/vps/' . $serviceName . '/veeam/restorePoints/' . rawurlencode($rpId) . '/restore', [
                        'full' => true,
                    ]);
                    return self::processing('msg_veeam_restoring');
                case 'disks_list':
                    return self::ok('', self::disks($client, $serviceName));
                case 'ips_list':
                    return self::ok('', self::ips($client, $serviceName));
                case 'reverse_set':
                    $ip = (string) ($input['ip'] ?? '');
                    $client->put('/vps/' . $serviceName . '/ips/' . rawurlencode($ip), [
                        'reverse' => (string) ($input['reverse'] ?? ''),
                    ]);
                    return self::ok('msg_reverse_updated');
                case 'ftp_status':
                    return self::ok('', self::safeGet($client, '/vps/' . $serviceName . '/backupftp'));
                case 'dns_list':
                    return self::ok('', self::safeGet($client, '/vps/' . $serviceName . '/secondaryDnsDomains'));
                case 'dns_add':
                    $client->post('/vps/' . $serviceName . '/secondaryDnsDomains', array_filter([
                        'domain' => (string) ($input['domain'] ?? ''),
                        'ip' => (string) ($input['ip'] ?? ''),
                    ]));
                    return self::ok('msg_dns_added');
                case 'dns_remove':
                    $client->delete('/vps/' . $serviceName . '/secondaryDnsDomains/' . rawurlencode((string) ($input['domain'] ?? '')));
                    return self::ok('msg_dns_removed');
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
     * (mod_ovhvps_option_map vps_os values) filtered by the family policy
     * ({@see ConfigOptions::canReinstallTo}). Excluded panel families
     * (cPanel/Plesk) are never offered; a locked family (Windows, paid per
     * order) locks both ways, so a Windows service only sees Windows images
     * and a non-Windows service never sees them; plain distros and the free
     * app images (Docker/n8n) are open to every Linux service, which is what
     * lets any customer reinstall into or out of Docker/n8n freely. Falls back
     * to the family filter alone when the plan OS names do not line up with
     * the image names, so reinstall always works but never leaks an excluded
     * or locked image.
     *
     * @param array<string, mixed> $params
     * @return list<array{id:string,name:string}>
     */
    private static function allowedImages(OvhClient $client, string $serviceName, array $params): array
    {
        $all = self::availableImages($client, $serviceName);

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $server = $serviceId > 0 ? (Database::getServer($serviceId) ?? []) : [];
        $currentOs = (string) ($server['os'] ?? '');

        // Family policy (every path): the pure reinstall rule against the
        // currently installed image.
        $familyOk = static function (array $img) use ($currentOs): bool {
            return ConfigOptions::canReinstallTo($currentOs, (string) ($img['name'] ?? ''));
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

        // Fallback: family filter only (never leaks an excluded or locked image).
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
        $imageId = (string) ($input['image_id'] ?? '');
        if ($imageId === '') {
            return self::err('msg_no_image');
        }
        // Server-side gate: the image must be in the plan's allowed list, so a
        // crafted request cannot reinstall to a managed image (paid or app image)
        // the customer is not entitled to.
        $images = self::allowedImages($client, $serviceName, $params);
        $allowedIds = array_map(static fn (array $img): string => (string) $img['id'], $images);
        if (!in_array($imageId, $allowedIds, true)) {
            return self::err('msg_os_not_available');
        }

        $osName = '';
        foreach ($images as $img) {
            if ((string) $img['id'] === $imageId) {
                $osName = (string) ($img['name'] ?? '');
                break;
            }
        }
        $serviceId = (int) ($params['serviceid'] ?? 0);

        // Windows (only offered when the service was ordered with Windows): an
        // SSH key is useless and suppressing the OVH password email would
        // discard the only credential source. Rebuild key-less so OVH mails
        // the password to the OVH account owner (the reseller), clear any old
        // Linux key material, and re-enter the bootstrap at 'none' so the cron
        // routes it through the manual-delivery path (IP mirror + admin
        // notification + credential-free customer email).
        if (AccessBootstrap::needsManualAccess($osName)) {
            AccessBootstrap::reinstallImage($client, $serviceName, $imageId);
            Database::upsertServer($serviceId, [
                'os' => $osName,
                'ssh_pubkey' => null,
                'ssh_privkey_enc' => null,
                'root_user' => null,
                'root_pass_enc' => null,
                'access_state' => 'none',
            ]);
            return self::processing('msg_reinstall_started_windows');
        }

        // Re-enter the access bootstrap so the customer gets fresh, emailed access
        // details exactly like a new order: rebuild with OUR management key (no OVH
        // password email), record it, and let the cron set + email the password.
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
        return self::processing('msg_reinstall_started');
    }

    /**
     * Whether the CURRENTLY INSTALLED image is n8n, decided (as everywhere in
     * the module) by the recorded OS image name containing "n8n". The recorded
     * os is refreshed on reinstall, so this follows the image, not the order.
     * Gates the additive n8n tab endpoint and the n8n URL email - never a
     * feature lock.
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
     * @return array{snapshot: mixed, optionEnabled: bool|null}
     */
    private static function snapshotInfo(OvhClient $client, string $serviceName): array
    {
        $snapshot = null;
        try {
            $snapshot = $client->get('/vps/' . $serviceName . '/snapshot');
        } catch (\Throwable $e) {
            // 404 when no snapshot exists.
        }
        return [
            'snapshot' => $snapshot,
            'optionEnabled' => self::activeOptionPresent($client, $serviceName, 'snapshot'),
        ];
    }

    /**
     * Whether an OVH VPS option (e.g. "snapshot", "automatedBackup", "veeam") is
     * currently active on the service. createSnapshot and the backup restores
     * require the matching option to be ordered first; without it OVH answers a
     * terse 400 whose message is just the serviceName. Returns null when the
     * option list cannot be read, so callers fail open rather than hide a working
     * feature on a transient API error.
     */
    private static function activeOptionPresent(OvhClient $client, string $serviceName, string $option): ?bool
    {
        $active = self::safeGet($client, '/vps/' . $serviceName . '/activeOptions');
        if (!is_array($active)) {
            return null;
        }
        return in_array($option, $active, true);
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
