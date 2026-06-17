<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Helper.php';
require __DIR__ . '/../lib/AccessBootstrap.php';

use OvhVps\Helper;
use OvhVps\AccessBootstrap;

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

done();
