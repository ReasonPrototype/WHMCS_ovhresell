<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/AccessBootstrap.php';

use OvhVps\AccessBootstrap;

// bootstrapCommand(): pure builder for the first-boot SSH command. It enables
// SSH password authentication (so the customer can log in as the OS default
// user with the password we email, exactly as the delivery email promises),
// sets the password for BOTH the default user and root, validates and reloads
// sshd, and prints the success sentinel only if every step succeeded.
$cmd = AccessBootstrap::bootstrapCommand('ubuntu', 'Secret123');

check('enables SSH password authentication', strpos($cmd, 'PasswordAuthentication yes') !== false, true);
check('writes the 00- drop-in that outranks cloud-init', strpos($cmd, '/etc/ssh/sshd_config.d/00-ovhvps.conf') !== false, true);
check('validates sshd config before trusting it', strpos($cmd, 'sshd -t') !== false, true);
check('sets the default user password', strpos($cmd, "'ubuntu:Secret123'") !== false, true);
check('sets the root password', strpos($cmd, "'root:Secret123'") !== false, true);
check('reloads ssh with a restart fallback', strpos($cmd, 'systemctl reload ssh || sudo systemctl restart ssh') !== false, true);
check('prints the success sentinel', strpos($cmd, 'OVHVPS_PW_OK') !== false, true);

// Root stays console-only: we flip PasswordAuthentication but never
// PermitRootLogin, so root is reachable through the KVM console, not by SSH.
check('does not enable root login over SSH', strpos($cmd, 'PermitRootLogin') !== false, false);

// The sentinel must be gated behind every step (chained with &&), so success is
// only reported when password auth, both passwords, and the reload all worked.
check('sentinel is gated at the end of the chain', preg_match('/&&\s*echo OVHVPS_PW_OK\s*$/', $cmd) === 1, true);

// POSIX single-quote escaping: a quote in the password must be closed, escaped
// and reopened ('\'') so the shell never sees an unbalanced quote.
$escaped = AccessBootstrap::bootstrapCommand('ubuntu', "a'b");
check('escapes a single quote in the password', strpos($escaped, "a'\\''b") !== false, true);

// Hard rule (CLAUDE.md): no em-dash / en-dash / horizontal bar anywhere.
check('command has no dash glyphs', preg_match('/[\x{2013}\x{2014}\x{2015}]/u', $cmd), 0);

done();
