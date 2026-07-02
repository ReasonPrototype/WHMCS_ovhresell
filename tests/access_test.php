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

// --- ConfigOptions::imageFamily / isSellableImage / normalizeOsName ---
// The family token only IDENTIFIES a special image; the POLICY lives in the
// EXCLUDED_FAMILIES / LOCKED_FAMILIES constants and their consumers.
check('windows family', ConfigOptions::imageFamily('Windows Server 2025'), 'windows');
check('cpanel family', ConfigOptions::imageFamily('Debian 12 - cPanel'), 'cpanel');
check('plesk family', ConfigOptions::imageFamily('Plesk on Debian 12'), 'plesk');
check('docker family', ConfigOptions::imageFamily('Debian 12 - Docker'), 'docker');
check('n8n family', ConfigOptions::imageFamily('Debian 12 - n8n'), 'n8n');
check('plain debian family', ConfigOptions::imageFamily('Debian 12'), '');
check('plain ubuntu family', ConfigOptions::imageFamily('Ubuntu 24.04'), '');
// Sellable = anything not carrying a panel license OVH would bill us for.
// Windows stays sellable (its paid license is attached to the order); the
// free app images (Docker/n8n) are ordinary Linux choices.
check('cpanel not sellable', ConfigOptions::isSellableImage('Debian 12 - cPanel'), false);
check('plesk not sellable', ConfigOptions::isSellableImage('Plesk on Debian 12'), false);
check('windows sellable', ConfigOptions::isSellableImage('Windows Server 2025'), true);
check('n8n sellable', ConfigOptions::isSellableImage('Debian 12 - n8n'), true);
check('docker sellable', ConfigOptions::isSellableImage('Debian 12 - Docker'), true);
check('debian sellable', ConfigOptions::isSellableImage('Debian 12'), true);
check('normalize os', ConfigOptions::normalizeOsName('  Debian   12 '), 'debian 12');

// --- AccessBootstrap::needsManualAccess (SSH bootstrap vs manual delivery) ---
// Windows images have no SSH daemon and no cloud-init default user, and a
// rebuild-with-key would wipe the OVH-installed licensed Windows. They must be
// routed to the manual-delivery path; every Linux image (plain or app) keeps
// the automated SSH bootstrap.
check('windows needs manual access', AccessBootstrap::needsManualAccess('Windows Server 2025 Datacenter Edition'), true);
check('windows lowercase needs manual access', AccessBootstrap::needsManualAccess('windows-server-2022'), true);
check('debian keeps ssh bootstrap', AccessBootstrap::needsManualAccess('Debian 12'), false);
check('n8n keeps ssh bootstrap', AccessBootstrap::needsManualAccess('Debian 12 - n8n'), false);
check('docker keeps ssh bootstrap', AccessBootstrap::needsManualAccess('Ubuntu 24.04 - Docker'), false);
check('empty os keeps ssh bootstrap', AccessBootstrap::needsManualAccess(''), false);

// --- Helper::lang: WHMCS 'portuguese' must map to European portuguese-pt, not fall to English ---
check('lang portuguese -> pt', Helper::lang('portuguese')['power_on'], 'Ligar');
check('lang portuguese-pt -> pt', Helper::lang('portuguese-pt')['power_on'], 'Ligar');
check('lang english stays en', Helper::lang('english')['power_on'], 'Power On');
check('lang unknown -> en', Helper::lang('klingon')['power_on'], 'Power On');

done();
