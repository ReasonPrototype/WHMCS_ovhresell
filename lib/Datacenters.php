<?php

namespace OvhVps;

/**
 * Maps OVH datacenter codes (as they appear in the public VPS catalog's
 * `vps_datacenter` configuration, e.g. "GRA", "WAW", "YNM") to human-friendly
 * "City, Country" labels for the customer-facing Datacenter dropdown.
 *
 * Pure and WHMCS-free so it stays unit-testable offline. An unrecognised code (a
 * new OVH location we have not mapped yet) falls back to the raw code, so the
 * option is always shown and the value sent to OVH is never affected: only the
 * visible label changes, the underlying ovh_value stays the OVH code.
 */
class Datacenters
{
    /**
     * Known OVH datacenter codes -> "City, Country" (English).
     *
     * Both the location code (GRA) and, where OVH uses one in the VPS catalog,
     * the country-style code (UK, DE) are listed so the lookup hits regardless of
     * which form a plan exposes. Unlisted codes fall back to the raw code.
     *
     * @var array<string, string>
     */
    public const NAMES = [
        'BHS' => 'Beauharnois, Canada',
        'GRA' => 'Gravelines, France',
        'SBG' => 'Strasbourg, France',
        'RBX' => 'Roubaix, France',
        'LIM' => 'Frankfurt, Germany',
        'DE'  => 'Frankfurt, Germany',
        'ERI' => 'London, United Kingdom',
        'UK'  => 'London, United Kingdom',
        'WAW' => 'Warsaw, Poland',
        'MIL' => 'Milan, Italy',
        'HIL' => 'Hillsboro, United States',
        'VIN' => 'Vint Hill, United States',
        'SGP' => 'Singapore',
        'SYD' => 'Sydney, Australia',
        'YNM' => 'Mumbai, India',
    ];

    /**
     * The friendly "City, Country" label for a datacenter code, or the raw code
     * (trimmed) when we do not recognise it. Case-insensitive on the code.
     */
    public static function label(string $code): string
    {
        $key = strtoupper(trim($code));
        return self::NAMES[$key] ?? trim($code);
    }
}
