<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Cancellation.php';

use OvhVps\Cancellation;

// Why this contract exists: pausing OVH auto-renew from the admin panel used to
// be an OVH-only action. The VPS expired at OVH while the WHMCS service stayed
// Active and kept invoicing the customer forever. The Pause Renewal button must
// therefore also register the WHMCS-side cancellation, and Resume Auto-Renew
// must take it back out.

// Pause with no request on file: register one (End of Billing Period), so the
// WHMCS cron cancels the service and stops invoicing at the due date.
check('stop registers a WHMCS cancellation when none is pending',
    Cancellation::planStop(false),
    [
        'addRequest' => true,
        'message' => 'OVH auto-renew paused and a WHMCS cancellation request (End of Billing Period) was registered: WHMCS cancels the service and stops invoicing at its due date.',
    ]);

// Pause when a request already exists (e.g. the customer cancelled via the
// client area, which is the flow that fires the CancellationRequest hook):
// never file a duplicate row, just report the existing one still applies.
check('stop never duplicates an existing cancellation request',
    Cancellation::planStop(true),
    [
        'addRequest' => false,
        'message' => 'OVH auto-renew paused; a WHMCS cancellation request already exists, so WHMCS still cancels the service at its due date.',
    ]);

// Resume with a pending request: remove it, otherwise WHMCS cancels a service
// the admin just decided to keep (the old button only printed a reminder).
check('resume removes the pending cancellation request',
    Cancellation::planResume(true),
    [
        'removeRequest' => true,
        'message' => 'OVH auto-renew resumed and the pending WHMCS cancellation request was removed.',
    ]);

check('resume with nothing pending only resumes',
    Cancellation::planResume(false),
    [
        'removeRequest' => false,
        'message' => 'OVH auto-renew resumed; there was no WHMCS cancellation request to remove.',
    ]);

// If AddCancelRequest fails the OVH side is already paused, so the admin must
// be told exactly what is missing - silence here recreates the original bug.
check('failed registration tells the admin what to do by hand',
    Cancellation::stopRegisterFailed('boom'),
    'OVH auto-renew paused, BUT registering the WHMCS cancellation failed: boom. Add a cancellation request (or terminate the service) manually, otherwise it stays Active and keeps invoicing after the VPS expires.');

// The API type string is load-bearing: WHMCS only defers the cancellation to
// the due date for exactly 'End of Billing Period'.
check('cancellation type is End of Billing Period',
    Cancellation::TYPE_END_OF_PERIOD,
    'End of Billing Period');

done();
