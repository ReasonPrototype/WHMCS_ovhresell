<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/OvhClient.php';

use OvhVps\OvhClient;

// An OVH request body must serialize as a JSON object, never a JSON array.
// Call sites build bodies with array_filter(), which yields [] once every
// optional field is empty. json_encode([]) === "[]" (a JSON array), which the
// API rejects with "Invalid JSON received: not a JSON object". An empty body
// must therefore collapse to null so the SDK sends an empty request body.
check('empty body collapses to null', OvhClient::normalizeBody([]), null);
check('null body stays null', OvhClient::normalizeBody(null), null);
check('populated body is preserved', OvhClient::normalizeBody(['description' => 'my snap']), ['description' => 'my snap']);

done();
