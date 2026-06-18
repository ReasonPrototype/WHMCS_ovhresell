<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Sends the customer-facing access emails through WHMCS. The single place that
 * builds merge variables and calls localAPI('SendEmail'). The root password is
 * passed in memory and injected via customvars at send time, so it is NEVER
 * persisted (not in tblhosting, not in mod_ovhvps_servers) - see the spec.
 */
class AccessMail
{
    /**
     * Encode merge variables the way WHMCS SendEmail expects: a base64-encoded
     * serialized array. Empty input yields '' (so the caller can omit the param).
     * Pure: no WHMCS/DB dependency.
     *
     * @param array<string, scalar> $vars
     */
    public static function customvars(array $vars): string
    {
        return $vars === [] ? '' : base64_encode(serialize($vars));
    }

    /**
     * Send a WHMCS product email template for a service, optionally injecting
     * extra merge variables that are not stored on the service.
     *
     * @param array<string, scalar> $vars
     */
    public static function send(int $serviceId, string $templateName, array $vars = []): void
    {
        if ($serviceId <= 0 || !function_exists('localAPI')) {
            return;
        }
        $payload = ['messagename' => $templateName, 'id' => $serviceId];
        $cv = self::customvars($vars);
        if ($cv !== '') {
            $payload['customvars'] = $cv;
        }
        localAPI('SendEmail', $payload);
        Helper::log('accessmail', ['service_id' => $serviceId, 'template' => $templateName], ['sent' => true], true, $serviceId);
    }

    /** Initial/after-reinstall access details. Password injected, never stored. */
    public static function sendAccessReady(int $serviceId, string $password): void
    {
        self::send($serviceId, Database::EMAIL_TEMPLATE, ['root_password' => $password]);
    }

    /** n8n URL email (web-only, no password). */
    public static function sendN8nReady(int $serviceId): void
    {
        self::send($serviceId, Database::EMAIL_TEMPLATE_N8N);
    }

    /** Confirmation after a customer-chosen password change (no password echoed). */
    public static function sendPasswordChanged(int $serviceId): void
    {
        self::send($serviceId, Database::EMAIL_TEMPLATE_PWCHANGED);
    }

    /** Model upgrade complete: the new product's description carries the specs. */
    public static function sendUpgradeComplete(int $serviceId): void
    {
        self::send($serviceId, Database::EMAIL_TEMPLATE_UPGRADE, ['product_specs' => self::productSpecs($serviceId)]);
    }

    /** The new product's description (tblproducts.description) for the service, or ''. */
    private static function productSpecs(int $serviceId): string
    {
        $pid = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        if ($pid <= 0) {
            return '';
        }
        return (string) Capsule::table('tblproducts')->where('id', $pid)->value('description');
    }
}
