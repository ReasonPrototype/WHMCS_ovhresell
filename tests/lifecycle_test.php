<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Lifecycle.php';

use OvhVps\Lifecycle;

// suspend: stop OVH auto-renewal so a non-paying service stops billing, but
// NEVER schedule deletion (the customer may still pay and be unsuspended).
check('suspend disables auto-renew', Lifecycle::renewFlags('suspend', ['automatic' => true]),
    ['automatic' => false, 'forced' => false]);

check('suspend never schedules deletion', Lifecycle::renewFlags('suspend', ['automatic' => true, 'deleteAtExpiration' => false]),
    ['automatic' => false, 'deleteAtExpiration' => false, 'forced' => false]);

// A customer who already requested cancellation keeps the pending deletion.
check('suspend preserves a pending deletion', Lifecycle::renewFlags('suspend', ['automatic' => false, 'deleteAtExpiration' => true, 'forced' => true]),
    ['automatic' => false, 'deleteAtExpiration' => true, 'forced' => true]);

// unsuspend: a late payer is reactivated - resume auto-renewal and clear any
// deletion that may have been scheduled while suspended/cancelled.
check('unsuspend restores auto-renew and clears deletion', Lifecycle::renewFlags('unsuspend', ['automatic' => false, 'deleteAtExpiration' => true]),
    ['automatic' => true, 'deleteAtExpiration' => false, 'forced' => false]);

check('unsuspend from a clean block', Lifecycle::renewFlags('unsuspend', ['automatic' => false]),
    ['automatic' => true, 'deleteAtExpiration' => false, 'forced' => false]);

done();
