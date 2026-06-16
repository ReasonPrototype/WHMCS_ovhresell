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

// --- map(): an OS image row carries its implied license addon ---
$winRows = [[
    'whmcs_option_group' => 'Operating System',
    'whmcs_option_value' => 'Windows Server 2022 Standard (Desktop)',
    'ovh_kind' => 'config',
    'ovh_label' => 'vps_os',
    'ovh_value' => 'Windows Server 2022 Standard (Desktop)',
    'ovh_option_plan_code' => 'option-windows-2025-model4',
]];
check('map Windows image -> config + windows license', ConfigOptions::map(
    ['Operating System' => 'Windows Server 2022 Standard (Desktop)'],
    $winRows
), [
    'config' => [['label' => 'vps_os', 'value' => 'Windows Server 2022 Standard (Desktop)']],
    'options' => [['planCode' => 'option-windows-2025-model4', 'quantity' => 1]],
]);

$linRows = [[
    'whmcs_option_group' => 'Operating System',
    'whmcs_option_value' => 'Debian 12',
    'ovh_kind' => 'config',
    'ovh_label' => 'vps_os',
    'ovh_value' => 'Debian 12',
    'ovh_option_plan_code' => 'option-linux',
]];
check('map Linux image -> config + linux license', ConfigOptions::map(
    ['Operating System' => 'Debian 12'],
    $linRows
), [
    'config' => [['label' => 'vps_os', 'value' => 'Debian 12']],
    'options' => [['planCode' => 'option-linux', 'quantity' => 1]],
]);

// A config row with no implied license (e.g. Datacenter) must NOT add an option.
$dcRows = [[
    'whmcs_option_group' => 'Datacenter',
    'whmcs_option_value' => 'GRA',
    'ovh_kind' => 'config',
    'ovh_label' => 'vps_datacenter',
    'ovh_value' => 'GRA',
    'ovh_option_plan_code' => null,
]];
check('map datacenter -> config only, no option', ConfigOptions::map(
    ['Datacenter' => 'GRA'],
    $dcRows
), [
    'config' => [['label' => 'vps_datacenter', 'value' => 'GRA']],
    'options' => [],
]);

done();
