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

// --- parseDatacenterMatrix ---
$raw = ['datacenters' => [
    ['datacenter' => 'GRA', 'status' => 'available',    'linuxStatus' => 'available',    'windowsStatus' => 'available'],
    ['datacenter' => 'WAW', 'status' => 'available',    'linuxStatus' => 'out-of-stock', 'windowsStatus' => 'available'],
    ['datacenter' => 'MIL', 'status' => 'out-of-stock', 'linuxStatus' => 'out-of-stock', 'windowsStatus' => 'out-of-stock'],
    ['datacenter' => 'XYZ', 'status' => 'available'],
    ['datacenter' => 'OLD', 'status' => 'out-of-stock'],
]];
check('matrix parse', Availability::parseDatacenterMatrix($raw), [
    ['datacenter' => 'GRA', 'linux' => true,  'windows' => true],
    ['datacenter' => 'WAW', 'linux' => false, 'windows' => true],
    ['datacenter' => 'MIL', 'linux' => false, 'windows' => false],
    ['datacenter' => 'XYZ', 'linux' => true,  'windows' => true],
    ['datacenter' => 'OLD', 'linux' => false, 'windows' => false],
]);
check('matrix bare strings', Availability::parseDatacenterMatrix(['SBG', 'GRA']), [
    ['datacenter' => 'SBG', 'linux' => true, 'windows' => true],
    ['datacenter' => 'GRA', 'linux' => true, 'windows' => true],
]);
check('matrix junk -> []', Availability::parseDatacenterMatrix('nope'), []);

done();
