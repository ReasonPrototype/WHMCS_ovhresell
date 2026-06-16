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

// --- decodeMatrix ---
check('decode new shape', Availability::decodeMatrix('[{"datacenter":"WAW","linux":false,"windows":true}]'), [
    ['datacenter' => 'WAW', 'linux' => false, 'windows' => true],
]);
check('decode legacy strings', Availability::decodeMatrix('["GRA","SBG"]'), [
    ['datacenter' => 'GRA', 'linux' => true, 'windows' => true],
    ['datacenter' => 'SBG', 'linux' => true, 'windows' => true],
]);
check('decode junk -> []', Availability::decodeMatrix('garbage'), []);
check('decode empty -> []', Availability::decodeMatrix('[]'), []);

// --- matrixAllows ---
$m = [
    ['datacenter' => 'WAW', 'linux' => false, 'windows' => true],
    ['datacenter' => 'GRA', 'linux' => true,  'windows' => true],
    ['datacenter' => 'MIL', 'linux' => false, 'windows' => false],
];
check('WAW + windows -> true', Availability::matrixAllows($m, 'WAW', 'Windows Server 2022'), true);
check('WAW + linux -> false', Availability::matrixAllows($m, 'WAW', 'Debian 12'), false);
check('GRA + linux -> true', Availability::matrixAllows($m, 'GRA', 'Debian 12'), true);
check('GRA + null -> true', Availability::matrixAllows($m, 'GRA', null), true);
check('MIL + null -> false', Availability::matrixAllows($m, 'MIL', null), false);
check('unknown dc -> false', Availability::matrixAllows($m, 'NOPE', 'Debian 12'), false);
check('case-insensitive dc', Availability::matrixAllows($m, 'waw', 'Windows Server 2022'), true);
check('empty matrix -> lenient true', Availability::matrixAllows([], 'WAW', 'Debian 12'), true);

done();
