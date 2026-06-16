<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Term.php';

use OvhVps\Term;

// --- billingCycleMonths ---
check('Monthly', Term::billingCycleMonths('Monthly'), 1);
check('Quarterly', Term::billingCycleMonths('Quarterly'), 3);
check('Semi-Annually', Term::billingCycleMonths('Semi-Annually'), 6);
check('Annually', Term::billingCycleMonths('Annually'), 12);
check('Biennially', Term::billingCycleMonths('Biennially'), 24);
check('Triennially', Term::billingCycleMonths('Triennially'), 36);
check('Free -> null', Term::billingCycleMonths('Free'), null);
check('One Time -> null', Term::billingCycleMonths('One Time'), null);
check('Bogus -> null', Term::billingCycleMonths('Bogus'), null);

// --- resolveOrderDuration ---
check(
    'upfront engagement P12M',
    Term::resolveOrderDuration(['engagementConfiguration' => ['duration' => 'P12M', 'type' => 'upfront']]),
    'P12M'
);
check(
    'upfront engagement P6M',
    Term::resolveOrderDuration(['engagementConfiguration' => ['duration' => 'P6M', 'type' => 'upfront']]),
    'P6M'
);
check(
    'monthly default -> P1M',
    Term::resolveOrderDuration(['interval' => 1, 'intervalUnit' => 'month']),
    'P1M'
);
check(
    'missing interval -> P1M',
    Term::resolveOrderDuration(['mode' => 'default']),
    'P1M'
);
check('interval 3 months -> P3M', Term::resolveOrderDuration(['interval' => 3, 'intervalUnit' => 'month']), 'P3M');
check('interval 0 clamps -> P1M', Term::resolveOrderDuration(['interval' => 0, 'intervalUnit' => 'month']), 'P1M');

// --- pickPricing ---
// Mirrors the real vps-2025-model1 (PT) catalog shape: zero-priced
// installation/upgrade entries plus the three purchasable renew entries.
$pricings = [
    ['mode' => 'default', 'commitment' => 0, 'capacities' => ['installation'], 'price' => 0, 'interval' => 0, 'intervalUnit' => 'none'],
    ['mode' => 'default', 'commitment' => 0, 'capacities' => ['renew'], 'price' => 649000000, 'interval' => 1, 'intervalUnit' => 'month'],
    ['mode' => 'upfront6', 'commitment' => 6, 'capacities' => ['renew'], 'price' => 617000000, 'interval' => 1, 'intervalUnit' => 'month', 'engagementConfiguration' => ['duration' => 'P6M', 'type' => 'upfront']],
    ['mode' => 'upfront12', 'commitment' => 12, 'capacities' => ['renew'], 'price' => 552000000, 'interval' => 1, 'intervalUnit' => 'month', 'engagementConfiguration' => ['duration' => 'P12M', 'type' => 'upfront']],
];

check('1mo -> default/P1M', Term::pickPricing($pricings, 1), ['pricingMode' => 'default', 'duration' => 'P1M']);
check('3mo -> default/P1M', Term::pickPricing($pricings, 3), ['pricingMode' => 'default', 'duration' => 'P1M']);
check('6mo -> upfront6/P6M', Term::pickPricing($pricings, 6), ['pricingMode' => 'upfront6', 'duration' => 'P6M']);
check('12mo -> upfront12/P12M', Term::pickPricing($pricings, 12), ['pricingMode' => 'upfront12', 'duration' => 'P12M']);
check('24mo -> upfront12/P12M', Term::pickPricing($pricings, 24), ['pricingMode' => 'upfront12', 'duration' => 'P12M']);
check('36mo -> upfront12/P12M', Term::pickPricing($pricings, 36), ['pricingMode' => 'upfront12', 'duration' => 'P12M']);

check('empty -> null', Term::pickPricing([], 12), null);

$addonDefaultOnly = [
    ['mode' => 'default', 'commitment' => 0, 'capacities' => ['renew'], 'price' => 199000000, 'interval' => 1, 'intervalUnit' => 'month'],
];
check('addon default-only, 12mo -> default/P1M', Term::pickPricing($addonDefaultOnly, 12), ['pricingMode' => 'default', 'duration' => 'P1M']);

$committedOnly = [
    ['mode' => 'upfront6', 'commitment' => 6, 'capacities' => ['renew'], 'price' => 617000000, 'interval' => 1, 'intervalUnit' => 'month', 'engagementConfiguration' => ['duration' => 'P6M']],
    ['mode' => 'upfront12', 'commitment' => 12, 'capacities' => ['renew'], 'price' => 552000000, 'interval' => 1, 'intervalUnit' => 'month', 'engagementConfiguration' => ['duration' => 'P12M']],
];
check('committed-only, 1mo -> null (no over-commit)', Term::pickPricing($committedOnly, 1), null);

done();
