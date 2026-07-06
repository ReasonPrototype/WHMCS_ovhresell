<?php

namespace OvhVps;

/**
 * Service lifecycle against OVH: suspend (stop), unsuspend (start), terminate
 * and cancellation.
 *
 * Suspend also pauses OVH auto-renewal (renew.automatic = false) so a
 * non-paying service stops being billed by OVH while it sits suspended; a
 * reactivated service resumes auto-renewal on unsuspend. This is what prevents
 * paying OVH for another month on a customer who simply stopped paying without
 * ever requesting cancellation.
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
        // Only trust the WHMCS "domain" field as an OVH identifier when it looks
        // like an OVH-assigned VPS name. The module auto-generates customer
        // hostnames (e.g. xxx.raiaweb-individual.pt) which OVH does not know, so
        // sending them to /vps/{name} returns "This service does not exist".
        // Returning null instead lets the client area show the clean
        // "provisioning" message until the cron resolves the real serviceName.
        $domain = trim((string) ($params['domain'] ?? ''));
        return ($domain !== '' && Helper::looksLikeOvhVpsName($domain)) ? $domain : null;
    }

    /**
     * Suspend: stop the VPS now and pause OVH auto-renewal, so a non-paying
     * service stops being billed by OVH while it sits suspended (WHMCS only
     * terminates later, if at all). The renewal change is best-effort: the
     * stop is what determines the WHMCS result.
     *
     * @param array<string, mixed> $params
     */
    public static function suspend(array $params): string
    {
        $result = self::power($params, 'stop', 'suspend');
        self::applyRenew($params, 'suspend');
        return $result;
    }

    /**
     * Unsuspend: start the VPS again and resume OVH auto-renewal (a late payer
     * has been reactivated), clearing any deletion scheduled while suspended.
     *
     * @param array<string, mixed> $params
     */
    public static function unsuspend(array $params): string
    {
        $result = self::power($params, 'start', 'unsuspend');
        self::applyRenew($params, 'unsuspend');
        return $result;
    }

    /**
     * Apply a suspend/unsuspend renewal transition against OVH: GET the current
     * renew block, run it through {@see renewFlags()}, PUT it back. Best-effort
     * and logged - a renewal error must not fail the WHMCS suspend/unsuspend.
     *
     * @param array<string, mixed> $params
     */
    private static function applyRenew(array $params, string $intent): void
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        try {
            $serviceName = self::serviceName($params);
            if ($serviceName === null) {
                return; // nothing provisioned yet
            }
            $client = OvhClient::fromParams($params);
            $info = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
            $renew = self::renewFlags($intent, is_array($info['renew'] ?? null) ? $info['renew'] : []);
            $client->put('/vps/' . $serviceName . '/serviceInfos', ['renew' => $renew]);
            Helper::log('renew:' . $intent, ['service' => $serviceName], $renew, true, $serviceId);
        } catch (\Throwable $e) {
            Helper::log('renew:' . $intent, null, $e->getMessage(), false, $serviceId);
        }
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
     * Compute the OVH `renew` block for a suspend/unsuspend transition,
     * preserving unrelated flags. Pure (no HTTP) so it stays unit-testable
     * offline; {@see applyRenew()} does the GET/PUT around it.
     *
     *  - 'suspend'   stop auto-renewal so OVH stops billing a non-paying
     *                service, but NEVER schedule deletion (the customer may
     *                still pay and be unsuspended); a deletion already
     *                scheduled (e.g. a prior cancellation) is preserved.
     *  - 'unsuspend' a late payer is reactivated: resume auto-renewal and
     *                clear any pending deletion.
     *  - 'cancel'    customer cancellation: pause auto-renewal so OVH stops
     *                billing the reseller. Identical renew transform to
     *                'suspend'; the VPS lapses at expiration and OVH deletes it
     *                after its grace period. Deliberately never sets
     *                deleteAtExpiration (that write returns OVH "Arguments
     *                conflicting").
     *
     * @param array<string, mixed> $renew current renew block from serviceInfos
     * @return array<string, mixed> the renew block to PUT back
     */
    public static function renewFlags(string $intent, array $renew): array
    {
        if ($intent === 'suspend' || $intent === 'cancel') {
            $renew['automatic'] = false;
        } elseif ($intent === 'unsuspend') {
            $renew['automatic'] = true;
            $renew['deleteAtExpiration'] = false;
        }
        $renew['forced'] = $renew['forced'] ?? false;
        return $renew;
    }

    /**
     * Pause OVH auto-renewal for a cancelled service so the reseller stops being
     * billed, WITHOUT writing deleteAtExpiration (that PUT returns OVH "Arguments
     * conflicting"). A non-renewing VPS lapses at the end of the paid term and
     * OVH deletes it after its grace period. GET the current renew block, flip
     * only `automatic`, PUT it back, then GET-verify the change took.
     */
    public static function stopRenewal(OvhClient $client, string $serviceName): void
    {
        $info = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
        $renew = self::renewFlags('cancel', is_array($info['renew'] ?? null) ? $info['renew'] : []);
        self::putRenewVerified($client, $serviceName, $renew, false);
    }

    /**
     * PUT a renew block to serviceInfos, then re-GET and assert that
     * `renew.automatic` landed on the expected value. OVH's serviceInfos PUT can
     * silently no-op on some renew combinations, so this read-back is what
     * guarantees the reseller's billing state actually changed.
     *
     * @param array<string, mixed> $renew
     */
    public static function putRenewVerified(OvhClient $client, string $serviceName, array $renew, bool $expectAutomatic): void
    {
        $client->put('/vps/' . $serviceName . '/serviceInfos', ['renew' => $renew]);
        $after = (array) $client->get('/vps/' . $serviceName . '/serviceInfos');
        $got = (bool) (is_array($after['renew'] ?? null) ? ($after['renew']['automatic'] ?? false) : false);
        if ($got !== $expectAutomatic) {
            throw new \RuntimeException(
                'OVH did not apply renew.automatic=' . ($expectAutomatic ? 'true' : 'false') . ' for ' . $serviceName . '.'
            );
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
     * Set the VPS root password to the customer-chosen value over SSH, then clear
     * it from WHMCS storage (we never persist credentials) and email a confirmation.
     * The old OVH /setPassword path (which emailed a random password to the reseller)
     * is replaced entirely.
     *
     * @param array<string, mixed> $params
     */
    public static function changePassword(array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $lang = Helper::lang((string) ($params['clientsdetails']['language'] ?? 'english'));
        $serviceName = self::serviceName($params);
        if ($serviceName === null) {
            return 'Error: ' . ($lang['msg_pw_no_service'] ?? 'no serviceName for this service.');
        }
        $newPassword = (string) ($params['password'] ?? '');
        if ($newPassword === '') {
            return 'Error: ' . ($lang['msg_pw_no_password'] ?? 'no new password provided.');
        }

        $server = Database::getServer($serviceId) ?? [];

        // Windows has no SSH path and its credentials are delivered manually;
        // never point the customer at a reinstall (it would wipe their Windows).
        if (AccessBootstrap::needsManualAccess((string) ($server['os'] ?? ''))) {
            return 'Error: ' . ($lang['msg_pw_windows'] ?? 'password changes for Windows are handled by our team. Please open a support ticket.');
        }

        $priv = Helper::decrypt((string) ($server['ssh_privkey_enc'] ?? ''));
        if ($priv === '') {
            return 'Error: ' . ($lang['msg_pw_not_ready'] ?? 'this VPS is not ready for a password change yet. Reinstall the OS from the client area first.');
        }

        try {
            $client = OvhClient::fromParams($params);
            $ip = (string) ($server['ip_main'] ?? '');
            if ($ip === '') {
                $info = Actions::info($client, $serviceName);
                $ip = (string) ($info['ip'] ?? '');
            }
            $user = (string) ($server['root_user'] ?? '') !== ''
                ? (string) $server['root_user']
                : AccessBootstrap::defaultUser((string) ($server['os'] ?? ''));

            if ($ip === '' || !AccessBootstrap::setPassword($ip, $user, $priv, $newPassword)) {
                return 'Error: ' . ($lang['msg_pw_failed'] ?? 'could not set the password (the VPS may be starting). Try again shortly.');
            }

            // Honour "never store the password": WHMCS native Change Password writes
            // it to tblhosting.password; clear it right after.
            \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->update(['password' => '']);

            AccessMail::sendPasswordChanged($serviceId);
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
