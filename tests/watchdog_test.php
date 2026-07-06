<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Term.php';
require __DIR__ . '/../lib/Watchdog.php';

use OvhVps\Watchdog;

/** Build one catalog pricing entry (raw price long, OVH 10^8 scale). */
function pricing(int $price, string $mode = 'default', int $commitment = 0, array $caps = ['renew']): array
{
    $p = [
        'mode' => $mode,
        'price' => $price,
        'commitment' => $commitment,
        'capacities' => $caps,
        'interval' => 1,
        'intervalUnit' => 'month',
    ];
    if ($commitment > 0) {
        $p['engagementConfiguration'] = ['duration' => 'P' . $commitment . 'M'];
    }
    return $p;
}

/** Build a plan snapshot in the shape diffPlan() consumes. */
function snapshot(array $os, array $pricings, array $options = [], bool $exists = true): array
{
    return ['exists' => $exists, 'os' => $os, 'pricings' => $pricings, 'options' => $options];
}

// --- pricingKeyMap: renewable terms only, keyed by mode + duration ---
check('pricingKeyMap keys renewable terms', Watchdog::pricingKeyMap([
    pricing(350000000),
    pricing(3600000000, 'upfront12', 12),
    pricing(100, 'default', 0, ['installation']), // one-shot: ignored
]), [
    'default P1M' => 350000000,
    'upfront12 P12M' => 3600000000,
]);
check('pricingKeyMap empty input', Watchdog::pricingKeyMap([]), []);

// --- priceChanges: changed, added and withdrawn terms ---
check('priceChanges none when identical', Watchdog::priceChanges(
    [pricing(350000000)],
    [pricing(350000000)]
), []);
check('priceChanges detects a raise', Watchdog::priceChanges(
    [pricing(350000000)],
    [pricing(380000000)]
), [
    ['term' => 'default P1M', 'old' => 350000000, 'new' => 380000000],
]);
check('priceChanges detects a withdrawn term', Watchdog::priceChanges(
    [pricing(350000000), pricing(3600000000, 'upfront12', 12)],
    [pricing(350000000)]
), [
    ['term' => 'upfront12 P12M', 'old' => 3600000000, 'new' => null],
]);
check('priceChanges detects a new term', Watchdog::priceChanges(
    [pricing(350000000)],
    [pricing(350000000), pricing(3600000000, 'upfront12', 12)]
), [
    ['term' => 'upfront12 P12M', 'old' => null, 'new' => 3600000000],
]);

// --- monthlyPrice: the no-commitment monthly term, scaled to currency units ---
check('monthlyPrice picks default P1M', Watchdog::monthlyPrice([
    pricing(3600000000, 'upfront12', 12),
    pricing(350000000),
]), 3.5);
check('monthlyPrice null when only committed terms', Watchdog::monthlyPrice([
    pricing(3600000000, 'upfront12', 12),
]), null);
check('formatPrice', Watchdog::formatPrice(350000000), '3.50');
check('formatPrice null', Watchdog::formatPrice(null), 'n/a');

// --- diffPlan: no change ---
$base = snapshot(
    ['Debian 12', 'Debian 12 - n8n'],
    [pricing(350000000)],
    ['option-linux' => ['description' => 'Linux', 'pricings' => [pricing(0)]]]
);
$same = Watchdog::diffPlan($base, $base);
check('diffPlan identical -> unchanged', $same['changed'], false);

// --- diffPlan: plan withdrawn suppresses the per-item noise ---
$gone = Watchdog::diffPlan($base, snapshot([], [], [], false));
check('diffPlan withdrawn -> changed', $gone['changed'], true);
check('diffPlan withdrawn -> missing', $gone['missing'], true);
check('diffPlan withdrawn suppresses os_removed', $gone['os_removed'], []);
check('diffPlan withdrawn suppresses options_removed', $gone['options_removed'], []);
check('diffPlan withdrawn suppresses price changes', $gone['plan_price_changes'], []);

// --- diffPlan: an OS image retired and another added ---
$osShift = Watchdog::diffPlan($base, snapshot(
    ['Debian 12', 'Debian 13'],
    [pricing(350000000)],
    ['option-linux' => ['description' => 'Linux', 'pricings' => [pricing(0)]]]
));
check('diffPlan os retired', $osShift['os_removed'], ['Debian 12 - n8n']);
check('diffPlan os added', $osShift['os_added'], ['Debian 13']);
check('diffPlan os shift -> changed', $osShift['changed'], true);

// --- diffPlan: plan price change ---
$priceUp = Watchdog::diffPlan($base, snapshot(
    ['Debian 12', 'Debian 12 - n8n'],
    [pricing(380000000)],
    ['option-linux' => ['description' => 'Linux', 'pricings' => [pricing(0)]]]
));
check('diffPlan plan price change', $priceUp['plan_price_changes'], [
    ['term' => 'default P1M', 'old' => 350000000, 'new' => 380000000],
]);

// --- diffPlan: addon removed / addon price change ---
$optShift = Watchdog::diffPlan(
    snapshot(['Debian 12'], [pricing(350000000)], [
        'snapshot-1' => ['description' => 'Snapshot', 'pricings' => [pricing(50000000)]],
        'backup-1' => ['description' => 'Backup', 'pricings' => [pricing(100000000)]],
    ]),
    snapshot(['Debian 12'], [pricing(350000000)], [
        'backup-1' => ['description' => 'Backup', 'pricings' => [pricing(120000000)]],
    ])
);
check('diffPlan addon removed', $optShift['options_removed'], ['snapshot-1']);
check('diffPlan addon price change', $optShift['option_price_changes'], [
    'backup-1' => [['term' => 'default P1M', 'old' => 100000000, 'new' => 120000000]],
]);

// --- livePlanSnapshot: extract one plan from a parsed catalog ---
$parsed = [
    'plans' => [
        ['plan_code' => 'vps-2025-model1', 'raw_json' => json_encode(['pricings' => [pricing(350000000)]])],
        ['plan_code' => 'vps-2025-model2', 'raw_json' => json_encode(['pricings' => [pricing(700000000)]])],
    ],
    'os' => [
        ['plan_code' => 'vps-2025-model1', 'os' => 'Debian 12'],
        ['plan_code' => 'vps-2025-model2', 'os' => 'Ubuntu 24.04'],
    ],
    'options' => [
        ['plan_code' => 'vps-2025-model1', 'option_plan_code' => 'option-linux', 'description' => 'Linux', 'raw_json' => json_encode(['pricings' => [pricing(0)]])],
    ],
];
$snap = Watchdog::livePlanSnapshot($parsed, 'vps-2025-model1');
check('livePlanSnapshot exists', $snap['exists'], true);
check('livePlanSnapshot os', $snap['os'], ['Debian 12']);
check('livePlanSnapshot pricing', Watchdog::monthlyPrice($snap['pricings']), 3.5);
check('livePlanSnapshot options', array_keys($snap['options']), ['option-linux']);
check('livePlanSnapshot absent plan', Watchdog::livePlanSnapshot($parsed, 'vps-x')['exists'], false);

// --- adminMessage: the alert email carries the essentials ---
$msg = Watchdog::adminMessage('PT', [
    'vps-2025-model1' => Watchdog::diffPlan($base, snapshot(
        ['Debian 12'],
        [pricing(380000000)],
        ['option-linux' => ['description' => 'Linux', 'pricings' => [pricing(0)]]]
    )),
], [
    ['product' => 'VPS-1', 'plan' => 'vps-2025-model1', 'whmcs' => 6.99, 'ovh' => 3.8],
], 'EUR');
check('adminMessage subject mentions plan count', str_contains($msg['subject'], '1 plan(s)'), true);
check('adminMessage names the plan', str_contains($msg['body'], 'vps-2025-model1'), true);
check('adminMessage shows retired image', str_contains($msg['body'], 'Debian 12 - n8n'), true);
check('adminMessage shows the price move', str_contains($msg['body'], '3.50 to 3.80 EUR'), true);
check('adminMessage shows the margin', str_contains($msg['body'], '<td>3.19</td>'), true);
check('adminMessage escapes html', str_contains(Watchdog::adminMessage('PT', [], [
    ['product' => 'A<b>c', 'plan' => 'p', 'whmcs' => null, 'ovh' => null],
], 'EUR')['body'], 'A&lt;b&gt;c'), true);

done();
