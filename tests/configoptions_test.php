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

// --- familySubOptions(): mandatory families drop "None"; optional ones keep it ---

// Optional family (mandatory flag off): "None" leads, then the addons in order.
check('familySubOptions optional keeps None first', ConfigOptions::familySubOptions([
    ['option_plan_code' => 'backup-1', 'description' => 'Daily', 'mandatory' => 0, 'is_default' => 0],
    ['option_plan_code' => 'backup-7', 'description' => 'Weekly', 'mandatory' => 0, 'is_default' => 0],
]), [
    ['label' => 'None', 'kind' => 'option', 'ovh_option_plan_code' => ''],
    ['label' => 'Daily', 'kind' => 'option', 'ovh_option_plan_code' => 'backup-1'],
    ['label' => 'Weekly', 'kind' => 'option', 'ovh_option_plan_code' => 'backup-7'],
]);

// Mandatory family with a default (e.g. storage on vps-2027): no "None", default
// addon promoted to first, the rest keeping catalog order.
check('familySubOptions mandatory drops None and leads with default', ConfigOptions::familySubOptions([
    ['option_plan_code' => 'storage-50', 'description' => '50 GB', 'mandatory' => 1, 'is_default' => 0],
    ['option_plan_code' => 'storage-100', 'description' => '100 GB', 'mandatory' => 1, 'is_default' => 1],
    ['option_plan_code' => 'storage-200', 'description' => '200 GB', 'mandatory' => 1, 'is_default' => 0],
]), [
    ['label' => '100 GB', 'kind' => 'option', 'ovh_option_plan_code' => 'storage-100'],
    ['label' => '50 GB', 'kind' => 'option', 'ovh_option_plan_code' => 'storage-50'],
    ['label' => '200 GB', 'kind' => 'option', 'ovh_option_plan_code' => 'storage-200'],
]);

// Mandatory family with no default (OVH default=null, e.g. 2025 automatedBackup):
// still no "None", addons keep catalog order so the first one is pre-selected.
check('familySubOptions mandatory no default keeps catalog order', ConfigOptions::familySubOptions([
    ['option_plan_code' => 'auto-backup-1', 'description' => '1 backup', 'mandatory' => 1, 'is_default' => 0],
    ['option_plan_code' => 'auto-backup-7', 'description' => '7 backups', 'mandatory' => 1, 'is_default' => 0],
]), [
    ['label' => '1 backup', 'kind' => 'option', 'ovh_option_plan_code' => 'auto-backup-1'],
    ['label' => '7 backups', 'kind' => 'option', 'ovh_option_plan_code' => 'auto-backup-7'],
]);

// Optional family with a default: "None" still leads (no silent charge), then the
// default addon, then the rest.
check('familySubOptions optional with default still leads with None', ConfigOptions::familySubOptions([
    ['option_plan_code' => 'backup-std', 'description' => 'Standard', 'mandatory' => 0, 'is_default' => 0],
    ['option_plan_code' => 'backup-adv', 'description' => 'Advanced', 'mandatory' => 0, 'is_default' => 1],
]), [
    ['label' => 'None', 'kind' => 'option', 'ovh_option_plan_code' => ''],
    ['label' => 'Advanced', 'kind' => 'option', 'ovh_option_plan_code' => 'backup-adv'],
    ['label' => 'Standard', 'kind' => 'option', 'ovh_option_plan_code' => 'backup-std'],
]);

// A blank description falls back to the planCode as the visible label.
check('familySubOptions blank description falls back to planCode', ConfigOptions::familySubOptions([
    ['option_plan_code' => 'storage-x', 'description' => '', 'mandatory' => 1, 'is_default' => 0],
]), [
    ['label' => 'storage-x', 'kind' => 'option', 'ovh_option_plan_code' => 'storage-x'],
]);

// --- canReinstallTo(): the reinstall family policy ---

// Linux images (plain distros and the free app images) swap freely, both ways.
check('reinstall linux -> linux', ConfigOptions::canReinstallTo('Debian 12', 'Ubuntu 24.04'), true);
check('reinstall linux -> docker', ConfigOptions::canReinstallTo('Debian 12', 'Debian 12 - Docker'), true);
check('reinstall linux -> n8n', ConfigOptions::canReinstallTo('Ubuntu 24.04', 'Debian 12 - n8n'), true);
check('reinstall docker -> n8n', ConfigOptions::canReinstallTo('Debian 12 - Docker', 'Debian 12 - n8n'), true);
check('reinstall n8n -> linux', ConfigOptions::canReinstallTo('Debian 12 - n8n', 'Debian 12'), true);
check('reinstall docker -> linux', ConfigOptions::canReinstallTo('Debian 12 - Docker', 'Rocky Linux 9'), true);

// Windows locks both ways: never in from Linux, never out to Linux.
check('reinstall linux -> windows blocked', ConfigOptions::canReinstallTo('Debian 12', 'Windows Server 2022 Standard'), false);
check('reinstall windows -> linux blocked', ConfigOptions::canReinstallTo('Windows Server 2022 Standard', 'Debian 12'), false);
check('reinstall windows -> docker blocked', ConfigOptions::canReinstallTo('Windows Server 2022 Standard', 'Debian 12 - Docker'), false);
check('reinstall windows -> windows ok', ConfigOptions::canReinstallTo('Windows Server 2022 Standard', 'Windows Server 2025 Datacenter'), true);
check('reinstall unknown current -> windows blocked', ConfigOptions::canReinstallTo('', 'Windows Server 2022'), false);

// Excluded panel families are refused for everyone, even bundled with Windows.
check('reinstall linux -> cpanel blocked', ConfigOptions::canReinstallTo('Debian 12', 'AlmaLinux 9 - cPanel'), false);
check('reinstall linux -> plesk blocked', ConfigOptions::canReinstallTo('Debian 12', 'Ubuntu 22.04 - Plesk'), false);
check('reinstall windows -> windows+plesk blocked', ConfigOptions::canReinstallTo('Windows Server 2022', 'Windows Server 2022 - Plesk'), false);

// isSellableImage refuses an excluded token wherever it appears in the name.
check('sellable plain linux', ConfigOptions::isSellableImage('Debian 12'), true);
check('sellable windows', ConfigOptions::isSellableImage('Windows Server 2022 Standard'), true);
check('sellable cpanel blocked', ConfigOptions::isSellableImage('AlmaLinux 9 - cPanel'), false);
check('sellable windows+plesk blocked', ConfigOptions::isSellableImage('Windows Server 2022 - Plesk'), false);

done();
