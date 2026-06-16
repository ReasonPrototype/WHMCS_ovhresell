<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/ConfigOptions.php';
require __DIR__ . '/../lib/Availability.php';

use OvhVps\Availability;
use OvhVps\ConfigOptions;

// --- osIsWindows ---
check('windows image -> true', ConfigOptions::osIsWindows('Windows Server 2022'), true);
check('windows mixed case -> true', ConfigOptions::osIsWindows('foo-WINDOWS-2025'), true);
check('debian -> false', ConfigOptions::osIsWindows('Debian 12'), false);
check('ubuntu -> false', ConfigOptions::osIsWindows('Ubuntu 24.04'), false);

done();
