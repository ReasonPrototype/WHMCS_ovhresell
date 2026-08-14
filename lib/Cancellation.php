<?php

namespace OvhVps;

/**
 * Pure decision logic for keeping the WHMCS side of a cancellation in sync
 * with the OVH side.
 *
 * Why it exists: the admin "Pause Renewal" button used to be OVH-only. It
 * paused renew.automatic at OVH, the VPS expired and was deleted, while the
 * WHMCS service stayed Active and kept generating renewal invoices for a
 * customer who had properly cancelled. The fix: pausing renewal also registers
 * a WHMCS cancellation request (End of Billing Period), so the WHMCS cron
 * cancels the service and stops invoicing at the due date; resuming renewal
 * removes that request again.
 *
 * This class stays free of WHMCS/HTTP dependencies (no Capsule, no localAPI)
 * so it remains unit-testable offline; {@see AdminActions} does the actual
 * database lookup, localAPI call and row removal around it.
 */
class Cancellation
{
    /**
     * WHMCS AddCancelRequest type. Load-bearing string: WHMCS only defers the
     * cancellation to the service's due date for exactly this value; anything
     * else is treated as an immediate cancellation.
     */
    public const TYPE_END_OF_PERIOD = 'End of Billing Period';

    /** Reason recorded on the request so the origin is obvious in the admin. */
    public const REASON = 'Requested via the OVH VPS admin panel (Pause Renewal)';

    /**
     * Plan the WHMCS side of "Pause Renewal": register a cancellation request
     * unless one already exists (the client-area flow may have filed it first,
     * and a duplicate row would show twice in the admin cancellations list).
     *
     * @param bool $alreadyRequested a cancellation request is already on file
     * @return array{addRequest: bool, message: string}
     */
    public static function planStop(bool $alreadyRequested): array
    {
        if ($alreadyRequested) {
            return [
                'addRequest' => false,
                'message' => 'OVH auto-renew paused; a WHMCS cancellation request already exists, so WHMCS still cancels the service at its due date.',
            ];
        }
        return [
            'addRequest' => true,
            'message' => 'OVH auto-renew paused and a WHMCS cancellation request (' . self::TYPE_END_OF_PERIOD . ') was registered: WHMCS cancels the service and stops invoicing at its due date.',
        ];
    }

    /**
     * Plan the WHMCS side of "Resume Auto-Renew": a pending cancellation
     * request must be removed, otherwise WHMCS cancels a service the admin
     * just decided to keep.
     *
     * @param bool $alreadyRequested a cancellation request is on file
     * @return array{removeRequest: bool, message: string}
     */
    public static function planResume(bool $alreadyRequested): array
    {
        if ($alreadyRequested) {
            return [
                'removeRequest' => true,
                'message' => 'OVH auto-renew resumed and the pending WHMCS cancellation request was removed.',
            ];
        }
        return [
            'removeRequest' => false,
            'message' => 'OVH auto-renew resumed; there was no WHMCS cancellation request to remove.',
        ];
    }

    /**
     * Message for the half-done state: OVH renewal is already paused but the
     * WHMCS cancellation could not be registered. The admin must be told what
     * to do by hand, because leaving this silent is exactly the original bug
     * (service stays Active and keeps invoicing after the VPS expires).
     */
    public static function stopRegisterFailed(string $error): string
    {
        return 'OVH auto-renew paused, BUT registering the WHMCS cancellation failed: ' . $error
            . '. Add a cancellation request (or terminate the service) manually, otherwise it stays Active and keeps invoicing after the VPS expires.';
    }
}
