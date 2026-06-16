<?php

namespace OvhVps;

/**
 * Pure logic to map a WHMCS billing cycle + an OVH plan/addon `pricings` array
 * to the OVH order `(duration, pricingMode)`. No WHMCS/HTTP dependencies, so it
 * is fully unit-testable offline.
 */
class Term
{
    /** WHMCS billingcycle -> committed months, or null when not a fixed recurring term. */
    public static function billingCycleMonths(string $cycle): ?int
    {
        $map = [
            'Monthly' => 1,
            'Quarterly' => 3,
            'Semi-Annually' => 6,
            'Annually' => 12,
            'Biennially' => 24,
            'Triennially' => 36,
        ];
        return $map[$cycle] ?? null;
    }

    /**
     * The OVH order `duration` string for one pricing entry.
     *
     * For committed (`upfront*`) modes OVH exposes the engagement duration
     * (e.g. P12M); for monthly `default` we build it from interval/unit (P1M).
     *
     * @param array<string, mixed> $pricing one entry of a plan/addon `pricings`
     */
    public static function resolveOrderDuration(array $pricing): string
    {
        $eng = $pricing['engagementConfiguration'] ?? null;
        if (is_array($eng) && isset($eng['duration']) && $eng['duration'] !== '') {
            return (string) $eng['duration'];
        }

        $interval = (int) ($pricing['interval'] ?? 1);
        if ($interval < 1) {
            $interval = 1;
        }
        $unit = strtolower((string) ($pricing['intervalUnit'] ?? 'month'));
        $code = ['day' => 'D', 'week' => 'W', 'month' => 'M', 'year' => 'Y'][$unit] ?? 'M';

        return 'P' . $interval . $code;
    }

    /**
     * Pick the best-fit pricing: the largest commitment that does NOT exceed
     * `$termMonths`. If nothing fits, prefer the monthly `default`; never pick a
     * commitment longer than the customer's term. Returns null when there is no
     * safe option (caller then falls back to the product config).
     *
     * @param array<int, array<string, mixed>> $pricings a plan/addon `pricings` array
     * @return array{pricingMode: string, duration: string}|null
     */
    public static function pickPricing(array $pricings, int $termMonths): ?array
    {
        $candidates = [];
        foreach ($pricings as $p) {
            if (!is_array($p)) {
                continue;
            }
            $caps = $p['capacities'] ?? [];
            if (!is_array($caps) || !in_array('renew', $caps, true)) {
                continue;
            }
            if ((int) ($p['price'] ?? 0) <= 0) {
                continue;
            }
            $commitment = (int) ($p['commitment'] ?? 0);
            $candidates[] = [
                'pricing' => $p,
                'commitment' => $commitment,
                'bounded' => $commitment > 0 ? $commitment : 1,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $fit = null;
        foreach ($candidates as $c) {
            if ($c['bounded'] <= $termMonths && ($fit === null || $c['bounded'] > $fit['bounded'])) {
                $fit = $c;
            }
        }

        if ($fit === null) {
            foreach ($candidates as $c) {
                if ($c['commitment'] === 0) {
                    $fit = $c;
                    break;
                }
            }
        }
        if ($fit === null) {
            return null;
        }

        return [
            'pricingMode' => (string) ($fit['pricing']['mode'] ?? 'default'),
            'duration' => self::resolveOrderDuration($fit['pricing']),
        ];
    }
}
