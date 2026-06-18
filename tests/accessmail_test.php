<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/AccessMail.php';

use OvhVps\AccessMail;

// customvars(): WHMCS SendEmail expects base64(serialize($vars)). Pure + reversible.
$enc = AccessMail::customvars(['root_password' => 'Abc123', 'x' => 'y']);
check('customvars roundtrips', unserialize(base64_decode($enc)), ['root_password' => 'Abc123', 'x' => 'y']);
check('customvars empty -> empty string', AccessMail::customvars([]), '');

done();
