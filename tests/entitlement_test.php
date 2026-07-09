<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/ConfigOptions.php';

use OvhVps\ConfigOptions;

// A paid OVH option family (snapshot, veeam) counts as purchased only when the
// customer's chosen configurable option resolves to an option-kind option_map
// row carrying a real OVH planCode. "None" carries an empty planCode, so it is
// not a purchase. This gates the client-area tabs that need a paid option.
$map = [
    ['whmcs_option_group' => 'Snapshot', 'whmcs_option_value' => 'None', 'ovh_kind' => 'option', 'ovh_option_plan_code' => ''],
    ['whmcs_option_group' => 'Snapshot', 'whmcs_option_value' => 'Snapshot', 'ovh_kind' => 'option', 'ovh_option_plan_code' => 'vps-snapshot-2027'],
    ['whmcs_option_group' => 'Veeam', 'whmcs_option_value' => 'None', 'ovh_kind' => 'option', 'ovh_option_plan_code' => ''],
    // A config-kind row (OS/datacenter) must never count as a paid option.
    ['whmcs_option_group' => 'Operating System', 'whmcs_option_value' => 'Debian 12', 'ovh_kind' => 'config', 'ovh_option_plan_code' => 'linux-license'],
];

check('null selection -> not purchased', ConfigOptions::familyPurchasedFrom('Snapshot', null, $map), false);
check('None selection -> not purchased', ConfigOptions::familyPurchasedFrom('Snapshot', 'None', $map), false);
check('real option selection -> purchased', ConfigOptions::familyPurchasedFrom('Snapshot', 'Snapshot', $map), true);
check('unpurchased family -> not purchased', ConfigOptions::familyPurchasedFrom('Veeam', 'None', $map), false);
check('unknown group -> not purchased', ConfigOptions::familyPurchasedFrom('Backup', 'Anything', $map), false);
check('config-kind row is never a paid option', ConfigOptions::familyPurchasedFrom('Operating System', 'Debian 12', $map), false);

done();
