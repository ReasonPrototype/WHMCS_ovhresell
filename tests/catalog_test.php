<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Catalog.php';

use OvhVps\Catalog;

// --- classifyFamily(): family name -> internal kind ---
check('classifyFamily storage -> disk', Catalog::classifyFamily('storage'), 'disk');
check('classifyFamily additionalDisk -> disk', Catalog::classifyFamily('additionalDisk'), 'disk');
check('classifyFamily automatedBackup -> backup', Catalog::classifyFamily('automatedBackup'), 'backup');
check('classifyFamily snapshot -> snapshot', Catalog::classifyFamily('snapshot'), 'snapshot');

// --- parse(): addon families carry OVH's mandatory flag and default planCode ---
// Mirrors the real catalog shape: a mandatory `storage` family with a default,
// plus an optional `automatedBackup` family with no default (default=null).
$catalog = [
    'addons' => [
        ['planCode' => 'storage-100', 'invoiceName' => '100 GB SSD'],
        ['planCode' => 'storage-200', 'invoiceName' => '200 GB SSD'],
        ['planCode' => 'backup-std', 'invoiceName' => 'Standard backup'],
    ],
    'plans' => [
        [
            'planCode' => 'vps-2027-model3',
            'invoiceName' => 'VPS 2027 Model 3',
            'addonFamilies' => [
                [
                    'name' => 'storage',
                    'mandatory' => true,
                    'default' => 'storage-200',
                    'addons' => ['storage-100', 'storage-200'],
                ],
                [
                    'name' => 'automatedBackup',
                    'mandatory' => false,
                    'default' => null,
                    'addons' => ['backup-std'],
                ],
            ],
        ],
    ],
];

$flat = array_map(static fn (array $o): array => [
    'family' => $o['family'],
    'option_plan_code' => $o['option_plan_code'],
    'mandatory' => $o['mandatory'],
    'is_default' => $o['is_default'],
], Catalog::parse($catalog)['options']);

check('parse carries mandatory and is_default per addon', $flat, [
    ['family' => 'disk', 'option_plan_code' => 'storage-100', 'mandatory' => 1, 'is_default' => 0],
    ['family' => 'disk', 'option_plan_code' => 'storage-200', 'mandatory' => 1, 'is_default' => 1],
    ['family' => 'backup', 'option_plan_code' => 'backup-std', 'mandatory' => 0, 'is_default' => 0],
]);

// A family with no mandatory/default keys at all must default to optional (0/0).
$bare = [
    'plans' => [[
        'planCode' => 'vps-x',
        'addonFamilies' => [[
            'name' => 'snapshot',
            'addons' => ['snap-1'],
        ]],
    ]],
];
$snap = Catalog::parse($bare)['options'][0];
check('parse missing flags default to optional', [
    'mandatory' => $snap['mandatory'],
    'is_default' => $snap['is_default'],
], ['mandatory' => 0, 'is_default' => 0]);

done();
