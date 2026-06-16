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
                    return self::ok('', self::images($client, $serviceName));
                case 'reinstall':
                    return self::reinstall($client, $serviceName, $input);
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
        $mainIp = is_array($ips) && $ips ? (string) $ips[0] : '';

        return [
            'name' => $vps['name'] ?? $serviceName,
            'displayName' => $vps['displayName'] ?? ($vps['name'] ?? $serviceName),
            'state' => $vps['state'] ?? 'unknown',
            'zone' => $vps['zone'] ?? ($vps['datacenter'] ?? ''),
            'memoryLimit' => isset($model['memory']) ? round(((int) $model['memory']) / 1024) : ($model['ram'] ?? ''),
            'disk' => $model['disk'] ?? '',
            'vcore' => $model['vcore'] ?? ($model['maximumVcoreCount'] ?? ''),
            'ip' => $mainIp,
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
     * @return array{available: array<int,mixed>, current: mixed}
     */
    private static function images(OvhClient $client, string $serviceName): array
    {
        return [
            'available' => (array) $client->get('/vps/' . $serviceName . '/images/available'),
            'current' => $client->get('/vps/' . $serviceName . '/images/current'),
        ];
    }

    /**
     * Reinstall/rebuild the VPS with a chosen image.
     *
     * @param array<string, mixed> $input
     * @return array{status:string, message:string, data:mixed}
     */
    private static function reinstall(OvhClient $client, string $serviceName, array $input): array
    {
        $imageId = (string) ($input['image_id'] ?? '');
        if ($imageId === '') {
            return self::err('No image selected.');
        }
        $body = ['imageId' => $imageId];
        if (!empty($input['ssh_key'])) {
            $sshKey = trim((string) $input['ssh_key']);
            // OVH 'sshKey' expects a key NAME from /me/sshKey; a raw public key
            // (has a space, or an algorithm prefix) must go in 'publicSshKey'.
            if (str_contains($sshKey, ' ') || preg_match('/^(ssh-|ecdsa-)/i', $sshKey) === 1) {
                $body['publicSshKey'] = $sshKey;
            } else {
                $body['sshKey'] = $sshKey;
            }
        }
        if (!empty($input['do_not_send_password'])) {
            $body['doNotSendPassword'] = true;
        }
        $client->post('/vps/' . $serviceName . '/rebuild', $body);
        return self::processing('Reinstall started. The VPS will reboot into the new OS.');
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
