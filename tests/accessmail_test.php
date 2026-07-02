<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/AccessMail.php';

use OvhVps\AccessMail;

// customvars(): WHMCS SendEmail expects base64(serialize($vars)). Pure + reversible.
$enc = AccessMail::customvars(['root_password' => 'Abc123', 'x' => 'y']);
check('customvars roundtrips', unserialize(base64_decode($enc)), ['root_password' => 'Abc123', 'x' => 'y']);
check('customvars empty -> empty string', AccessMail::customvars([]), '');

// adminManualAccessMessage(): pure builder for the reseller notification sent
// when a Windows VPS comes online. The module never knows the Windows password
// (OVH mails it to the OVH account owner), so the admin must deliver the login
// manually. Admin-facing, so English only.
$msg = AccessMail::adminManualAccessMessage(42, 'win.example.com', 'Windows Server 2025 Standard', '203.0.113.7');
check('admin subject', $msg['subject'], 'OVH VPS: manual access delivery needed for service #42 (win.example.com)');
check('admin body names the os', strpos($msg['body'], 'Windows Server 2025 Standard') !== false, true);
check('admin body names the ip', strpos($msg['body'], '203.0.113.7') !== false, true);
check('admin body points at the OVH Manager', strpos($msg['body'], 'OVH Manager') !== false, true);
check('admin body asks for manual delivery', stripos($msg['body'], 'manual') !== false, true);

// Without a hostname the subject still identifies the service by id.
$msg2 = AccessMail::adminManualAccessMessage(7, '', 'Windows Server 2022', '198.51.100.9');
check('admin subject without domain', $msg2['subject'], 'OVH VPS: manual access delivery needed for service #7');

// Hard rule: no em-dash / en-dash / horizontal bar anywhere in generated text.
check('admin message has no dash glyphs', preg_match('/[\x{2013}\x{2014}\x{2015}]/u', $msg['subject'] . $msg['body'] . $msg2['subject'] . $msg2['body']), 0);

done();
