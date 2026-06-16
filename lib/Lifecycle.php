<?php

namespace OvhVps;

/**
 * Service lifecycle against OVH: suspend (stop), unsuspend (start), terminate
 * and cancellation.
 *
 * Cancellation is automatic via renew.deleteAtExpiration (no email token): the
 * VPS is deleted by OVH at the end of the paid term and access is cut now with
 * a stop. Immediate hard-termination (POST /terminate) needs an emailed token,
 * so it is optional and confirmed by the admin via {@see confirmTermination()}.
 */
class Lifecycle
{
    /**
     * Resolve the OVH serviceName for a WHMCS service from our map, falling
     * back to the service domain field.
     *
     * @param array<string, mixed> $params
     */
    public static function serviceName(array $params): ?string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId > 0) {
            $server = Database::getServer($serviceId);
            if ($server && !empty($server['service_name'])) {
                return (string) $server['service_name'];
            }
        }
        $domain = trim((string) ($params['domain'] ?? ''));
        return $domain !== '' ? $domain : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function suspend(array $params): string
    {
        return self::power($params, 'stop', 'suspend');
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function unsuspend(array $params): string
    {
        return self::power($params, 'start', 'unsuspend');
    }

    /**
     * Terminate: schedule OVH deletion at term end (no token), stop now, and
     * optionally request immediate hard-termination (token confirmed later).
     *
     * @param array<string, mixed> $params
     */
    public static function terminate(array $params): string
    {
        $serviceName = self::serviceName($params);
        if ($serviceName === null) {
            // Nothing provisioned (or already gone) - treat as success.
            return 'success';
        }

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $cfg = Helper::cfg($params);
        $client = OvhClient::fromParams($params);

        try {
            if (Helper::bool($cfg['auto_delete_on_terminate'] ?: 'on')) {
                self::scheduleDeleteAtExpiration($client, $serviceName);
                Database::upsertServer($serviceId, ['delete_at_expiration' => 1, 'state' => 'terminating']);
            }

            // Cut access immediately (best-effort).
            try {
                $client->post('/vps/' . $serviceName . '/stop');
            } catch (\Throwable $e) {
                // VPS may already be stopped/deleting.
            }

            if (Helper::bool($cfg['immediate_terminate'])) {
                $token = $client->post('/vps/' . $serviceName . '/terminate');
                Helper::log('terminate:request', ['service' => $serviceName], $token, true, $serviceId);
                // OVH emails the confirmation token; mark it pending for the admin.
                self::markTokenPending($serviceId, true);
            }

            return 'success';
        } catch (\Throwable $e) {
            Helper::log('terminate', ['service' => $serviceName], $e->getMessage(), false, $serviceId);
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Schedule OVH deletion at the end of the paid term via serviceInfos.
     * GET the current renew block, flip the flags, PUT it back.
     */
    public static function scheduleDeleteAtExpiration(OvhClient $client, string $serviceName): void
    {
        $info = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
        $renew = is_array($info['renew'] ?? null) ? $info['renew'] : [];
        $renew['automatic'] = false;
        $renew['deleteAtExpiration'] = true;
        $renew['forced'] = $renew['forced'] ?? false;
        $client->put('/vps/' . $serviceName . '/serviceInfos', ['renew' => $renew]);
    }

    /**
     * Guarantee the service auto-renews and is NOT scheduled for deletion, so a
     * committed multi-year sale (ordered as a 1-year upfront on OVH) survives
     * past the first engagement via REACTIVATE_ENGAGEMENT. Mirror of
     * scheduleDeleteAtExpiration with the flags inverted. Called when a
     * serviceName becomes known (order success or cron resolution).
     */
    public static function ensureAutoRenew(OvhClient $client, string $serviceName): void
    {
        $info = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
        $renew = is_array($info['renew'] ?? null) ? $info['renew'] : [];
        $renew['automatic'] = true;
        $renew['deleteAtExpiration'] = false;
        $renew['forced'] = $renew['forced'] ?? false;
        $client->put('/vps/' . $serviceName . '/serviceInfos', ['renew' => $renew]);
    }

    /**
     * Cancel a previously scheduled deletion (re-enable the service).
     */
    public static function cancelDeleteAtExpiration(OvhClient $client, string $serviceName): void
    {
        $info = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
        $renew = is_array($info['renew'] ?? null) ? $info['renew'] : [];
        $renew['deleteAtExpiration'] = false;
        $renew['forced'] = $renew['forced'] ?? false;
        $client->put('/vps/' . $serviceName . '/serviceInfos', ['renew' => $renew]);
    }

    /**
     * Confirm an immediate termination with the token OVH emailed to the
     * account holder.
     *
     * @param array<string, mixed> $params
     */
    public static function confirmTermination(array $params, string $token, string $reason = 'Customer cancellation'): array
    {
        $serviceName = self::serviceName($params);
        if ($serviceName === null) {
            return ['success' => false, 'message' => 'No serviceName for this service.'];
        }
        try {
            OvhClient::fromParams($params)->post('/vps/' . $serviceName . '/confirmTermination', [
                'token' => $token,
                'reason' => $reason,
            ]);
            self::markTokenPending((int) ($params['serviceid'] ?? 0), false);
            return ['success' => true, 'message' => 'Termination confirmed for ' . $serviceName . '.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function changePassword(array $params): string
    {
        $serviceName = self::serviceName($params);
        if ($serviceName === null) {
            return 'Error: no serviceName for this service.';
        }
        try {
            OvhClient::fromParams($params)->post('/vps/' . $serviceName . '/setPassword');
            return 'success';
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function power(array $params, string $action, string $label): string
    {
        $serviceName = self::serviceName($params);
        if ($serviceName === null) {
            return 'Error: no serviceName for this service.';
        }
        try {
            OvhClient::fromParams($params)->post('/vps/' . $serviceName . '/' . $action);
            Helper::log($label, ['service' => $serviceName], 'ok', true, (int) ($params['serviceid'] ?? 0));
            return 'success';
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    private static function markTokenPending(int $serviceId, bool $pending): void
    {
        if ($serviceId <= 0) {
            return;
        }
        try {
            \WHMCS\Database\Capsule::table(Database::ORDERS)
                ->where('service_id', $serviceId)
                ->update(['terminate_token_pending' => $pending ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
