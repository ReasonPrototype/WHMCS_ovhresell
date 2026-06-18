<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Helper.php';
require __DIR__ . '/../lib/AccessBootstrap.php';
require __DIR__ . '/../lib/ConfigOptions.php';

use OvhVps\Helper;
use OvhVps\AccessBootstrap;
use OvhVps\ConfigOptions;

// --- looksLikeOvhVpsName ---
// True only for OVH-assigned VPS serviceNames, never for a WHMCS hostname.
check('modern ovh name', Helper::looksLikeOvhVpsName('vps-2bfa6fc6.vps.ovh.net'), true);
check('legacy ovh name', Helper::looksLikeOvhVpsName('vps123456.ovh.net'), true);
check('uppercase tolerated', Helper::looksLikeOvhVpsName('VPS-ABC.VPS.OVH.NET'), true);
check('whmcs hostname', Helper::looksLikeOvhVpsName('qe1lwo7mswrhvuuluo4f.raiaweb-individual.pt'), false);
check('random domain', Helper::looksLikeOvhVpsName('example.com'), false);
check('empty', Helper::looksLikeOvhVpsName(''), false);

// --- AccessBootstrap::defaultUser (OS -> cloud-image default sudo user) ---
check('debian user', AccessBootstrap::defaultUser('Debian 12'), 'debian');
check('ubuntu user', AccessBootstrap::defaultUser('Ubuntu 24.04'), 'ubuntu');
check('n8n image keeps debian', AccessBootstrap::defaultUser('Debian 12 - n8n'), 'debian');
check('unknown defaults to debian', AccessBootstrap::defaultUser('SomeOS 1.0'), 'debian');

// --- ConfigOptions::managedImageFamily / normalizeOsName ---
// Paid families (a license OVH bills us for) gate the reinstall list...
check('windows family', ConfigOptions::managedImageFamily('Windows Server 2025'), 'windows');
check('cpanel family', ConfigOptions::managedImageFamily('Debian 12 - cPanel'), 'cpanel');
check('plesk family', ConfigOptions::managedImageFamily('Plesk on Debian 12'), 'plesk');
// ...as do the application images we never want loose on a plain VPS.
check('docker family', ConfigOptions::managedImageFamily('Debian 12 - Docker'), 'docker');
check('n8n family', ConfigOptions::managedImageFamily('Debian 12 - n8n'), 'n8n');
// A plain distribution is free and generic: always offered (family '').
check('plain debian family', ConfigOptions::managedImageFamily('Debian 12'), '');
check('plain ubuntu family', ConfigOptions::managedImageFamily('Ubuntu 24.04'), '');
check('normalize os', ConfigOptions::normalizeOsName('  Debian   12 '), 'debian 12');

// --- Helper::lang: WHMCS 'portuguese' must map to European portuguese-pt, not fall to English ---
check('lang portuguese -> pt', Helper::lang('portuguese')['power_on'], 'Ligar');
check('lang portuguese-pt -> pt', Helper::lang('portuguese-pt')['power_on'], 'Ligar');
check('lang english stays en', Helper::lang('english')['power_on'], 'Power On');
check('lang unknown -> en', Helper::lang('klingon')['power_on'], 'Power On');

done();
