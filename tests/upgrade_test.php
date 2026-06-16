<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Upgrade.php';

use OvhVps\Upgrade;

// --- foldQty ---
check('foldQty sums dupes', Upgrade::foldQty([
    ['planCode' => 'ip', 'quantity' => 1],
    ['planCode' => 'ip', 'quantity' => 2],
    ['planCode' => 'backup', 'quantity' => 1],
]), ['ip' => 3, 'backup' => 1]);
check('foldQty skips empty planCode', Upgrade::foldQty([['planCode' => '', 'quantity' => 5]]), []);

// --- decodeOptions ---
check('decodeOptions null -> []', Upgrade::decodeOptions(null), []);
check('decodeOptions junk -> []', Upgrade::decodeOptions('not json'), []);
check('decodeOptions roundtrip', Upgrade::decodeOptions('[{"planCode":"backup","quantity":1}]'), [
    ['planCode' => 'backup', 'quantity' => 1],
]);

// --- detectChange ---
// No change at all -> noop.
check('noop', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model2',
    [['planCode' => 'backup', 'quantity' => 1]],
    [['planCode' => 'backup', 'quantity' => 1]]
), ['planUpgrade' => null, 'optionsToAdd' => [], 'optionsToRemove' => [], 'noop' => true]);

// Add one brand-new option (empty baseline = first upgrade).
check('add new option', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model2',
    [],
    [['planCode' => 'backup', 'quantity' => 1]]
), ['planUpgrade' => null, 'optionsToAdd' => [['planCode' => 'backup', 'quantity' => 1]], 'optionsToRemove' => [], 'noop' => false]);

// Increase a quantity-style option 1 -> 3 = add 2.
check('add quantity delta', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model2',
    [['planCode' => 'ip', 'quantity' => 1]],
    [['planCode' => 'ip', 'quantity' => 3]]
), ['planUpgrade' => null, 'optionsToAdd' => [['planCode' => 'ip', 'quantity' => 2]], 'optionsToRemove' => [], 'noop' => false]);

// Option dropped entirely -> removal (rejected by caller).
check('removal flagged', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model2',
    [['planCode' => 'veeam', 'quantity' => 1]],
    []
), ['planUpgrade' => null, 'optionsToAdd' => [], 'optionsToRemove' => ['veeam'], 'noop' => false]);

// Reduce a quantity 3 -> 1 -> removal/reduction flagged.
check('reduction flagged', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model2',
    [['planCode' => 'ip', 'quantity' => 3]],
    [['planCode' => 'ip', 'quantity' => 1]]
), ['planUpgrade' => null, 'optionsToAdd' => [], 'optionsToRemove' => ['ip'], 'noop' => false]);

// Plan change detected (target differs from current).
check('plan upgrade detected', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model4', [], []),
    ['planUpgrade' => 'vps-2025-model4', 'optionsToAdd' => [], 'optionsToRemove' => [], 'noop' => false]);

// Plan change + new option together.
check('plan + option', Upgrade::detectChange('vps-2025-model2', 'vps-2025-model4',
    [],
    [['planCode' => 'backup', 'quantity' => 1]]
), ['planUpgrade' => 'vps-2025-model4', 'optionsToAdd' => [['planCode' => 'backup', 'quantity' => 1]], 'optionsToRemove' => [], 'noop' => false]);


// --- extra coverage: quantity clamping ---
check('foldQty clamps zero qty to 1', Upgrade::foldQty([['planCode' => 'ip', 'quantity' => 0]]), ['ip' => 1]);
check('decodeOptions missing quantity -> 1', Upgrade::decodeOptions('[{"planCode":"backup"}]'), [['planCode' => 'backup', 'quantity' => 1]]);

done();
