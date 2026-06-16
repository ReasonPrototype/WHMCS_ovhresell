<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/ConfigOptions.php';

use OvhVps\ConfigOptions;

// --- pickOsLicenses: identify the Linux and Windows license planCodes ---
$osFamily = [
    ['option_plan_code' => 'option-linux', 'description' => 'Linux'],
    ['option_plan_code' => 'option-windows-2025-model4', 'description' => 'Windows Server'],
];
check('pickOsLicenses finds both', ConfigOptions::pickOsLicenses($osFamily), [
    'windows' => 'option-windows-2025-model4',
    'linux' => 'option-linux',
]);
check('pickOsLicenses linux only', ConfigOptions::pickOsLicenses([
    ['option_plan_code' => 'option-linux', 'description' => 'Linux'],
]), ['windows' => null, 'linux' => 'option-linux']);
check('pickOsLicenses windows only', ConfigOptions::pickOsLicenses([
    ['option_plan_code' => 'option-windows-2025-model4', 'description' => 'Windows Server'],
]), ['windows' => 'option-windows-2025-model4', 'linux' => null]);
check('pickOsLicenses empty', ConfigOptions::pickOsLicenses([]), [
    'windows' => null,
    'linux' => null,
]);

// --- impliedLicense: which license a chosen image needs ---
$lic = ['windows' => 'option-windows-2025-model4', 'linux' => 'option-linux'];
check('implied windows', ConfigOptions::impliedLicense('Windows Server 2022 Standard (Desktop)', $lic), 'option-windows-2025-model4');
check('implied debian -> linux', ConfigOptions::impliedLicense('Debian 12', $lic), 'option-linux');
check('implied ubuntu -> linux', ConfigOptions::impliedLicense('Ubuntu 22.04', $lic), 'option-linux');
check('implied windows but planCode missing -> null', ConfigOptions::impliedLicense('Windows Server 2022', ['windows' => null, 'linux' => 'option-linux']), null);

done();
