<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Helper.php';

use OvhVps\Helper;

// --- looksLikeOvhVpsName ---
// True only for OVH-assigned VPS serviceNames, never for a WHMCS hostname.
check('modern ovh name', Helper::looksLikeOvhVpsName('vps-2bfa6fc6.vps.ovh.net'), true);
check('legacy ovh name', Helper::looksLikeOvhVpsName('vps123456.ovh.net'), true);
check('uppercase tolerated', Helper::looksLikeOvhVpsName('VPS-ABC.VPS.OVH.NET'), true);
check('whmcs hostname', Helper::looksLikeOvhVpsName('qe1lwo7mswrhvuuluo4f.raiaweb-individual.pt'), false);
check('random domain', Helper::looksLikeOvhVpsName('example.com'), false);
check('empty', Helper::looksLikeOvhVpsName(''), false);

done();
