<?php

namespace OvhVps;

use Ovh\Api;
use WHMCS\Database\Capsule;

/**
 * Shared helpers: autoloading, OVH API client construction, module-setting
 * access and logging.
 */
class Helper
{
    /** Endpoints accepted in the server "Hostname" field. */
    public const VALID_ENDPOINTS = [
        'ovh-eu', 'ovh-ca', 'ovh-us',
        'kimsufi-eu', 'kimsufi-ca',
        'soyoustart-eu', 'soyoustart-ca',
    ];

    /**
     * Ordered list of product Module Settings.
     *
     * IMPORTANT: the ORDER defines the WHMCS configoption index
     * (configoption1 = first entry). Never reorder/remove without a migration,
     * or {@see Helper::cfg()} will read the wrong values.
     *
     * @return array<string, array<string, string>>
     */
    public static function configFields(): array
    {
        return [
            'subsidiary' => [
                'FriendlyName' => 'OVH Subsidiary',
                'Type' => 'text',
                'Size' => '6',
                'Default' => 'PT',
                'Description' => 'Ordering subsidiary (PT, FR, GB, DE, ES, IT, PL, IE, FI, LT, NL, CA, US...). Must match your OVH account country. Defaults to PT when left empty.',
            ],
            'plan_code' => [
                'FriendlyName' => 'VPS Plan Code',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'OVH catalog planCode for the range this product sells (e.g. vps-2025-model1 = VPS-1; the 6 codes are in the README). After saving, click "Generate OVH options" on the product page to build the OS/Datacenter/extra options.',
            ],
            'duration' => [
                'FriendlyName' => 'Billing Duration',
                'Type' => 'text',
                'Size' => '8',
                'Default' => 'P1M',
                'Description' => 'ISO-8601 duration for the OVH order (P1M = monthly, P12M = yearly).',
            ],
            'pricing_mode' => [
                'FriendlyName' => 'Pricing Mode',
                'Type' => 'text',
                'Size' => '16',
                'Default' => 'default',
                'Description' => 'OVH pricingMode for the order (usually "default").',
            ],
            'default_os' => [
                'FriendlyName' => 'Default OS',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Fallback OS when the customer does not pick one (e.g. Debian 12).',
            ],
            'default_datacenter' => [
                'FriendlyName' => 'Default Datacenter',
                'Type' => 'text',
                'Size' => '16',
                'Description' => 'Fallback datacenter when the customer does not pick one (e.g. GRA, SBG, BHS).',
            ],
            'auto_delete_on_terminate' => [
                'FriendlyName' => 'Auto Delete On Terminate',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'On termination/cancellation, schedule OVH deletion at end of the paid term (renew.deleteAtExpiration). Fully automatic, no email token.',
            ],
            'immediate_terminate' => [
                'FriendlyName' => 'Request Immediate Termination',
                'Type' => 'yesno',
                'Description' => 'Also fire OVH immediate termination (needs an emailed token confirmed by the admin). Leave off to rely on deleteAtExpiration only.',
            ],
        ];
    }

    /**
     * Read module settings into a name-keyed array, resolving the
     * configoption<N> indexes back to their internal names.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public static function cfg(array $params): array
    {
        $out = [];
        $i = 1;
        foreach (array_keys(self::configFields()) as $name) {
            $out[$name] = (string) ($params['configoption' . $i] ?? '');
            $i++;
        }
        return $out;
    }

    /**
     * One-time per-request boot: load Composer autoload and ensure the schema.
     */
    public static function init(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        try {
            Database::ensureSchema();
            Database::ensureEmailTemplate();
        } catch (\Throwable $e) {
            self::log('schema', null, ['error' => $e->getMessage()], false);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function endpointKey(array $params): string
    {
        $candidates = [
            $params['serverhostname'] ?? '',
            $params['endpoint'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if (in_array($candidate, self::VALID_ENDPOINTS, true)) {
                return $candidate;
            }
        }
        return 'ovh-eu';
    }

    /**
     * Build an authenticated OVH API client from the WHMCS server credentials.
     *
     * Username = Application Key, Password = Application Secret,
     * Access Hash = Consumer Key, Hostname = endpoint (ovh-eu/ovh-ca/ovh-us).
     *
     * @param array<string, mixed> $params Server module $params.
     */
    public static function api(array $params): Api
    {
        self::init();

        $appKey      = trim((string) ($params['serverusername'] ?? ''));
        $appSecret   = trim((string) ($params['serverpassword'] ?? ''));
        $consumerKey = trim((string) ($params['serveraccesshash'] ?? ''));
        $endpoint    = self::endpointKey($params);

        if ($appKey === '' || $appSecret === '') {
            throw new \RuntimeException(
                'OVH credentials missing. Set Application Key (Username) and Application Secret (Password) on the assigned server.'
            );
        }

        if (!class_exists(Api::class)) {
            throw new \RuntimeException(
                'OVH SDK not found. Run "composer install" inside modules/servers/ovhvps.'
            );
        }

        return new Api($appKey, $appSecret, $endpoint, $consumerKey !== '' ? $consumerKey : null);
    }

    public static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * True when a string looks like an OVH-assigned VPS serviceName
     * (e.g. "vps-2bfa6fc6.vps.ovh.net" or legacy "vps123456.ovh.net"), as
     * opposed to a WHMCS-generated hostname. Used to decide whether the WHMCS
     * service "domain" field can be trusted as an OVH identifier: the auto
     * hostname must never be sent to /vps/{name} or OVH 404s ("does not exist").
     */
    public static function looksLikeOvhVpsName(string $value): bool
    {
        $value = strtolower(trim($value));
        return $value !== '' && preg_match('/^vps[0-9a-z\-]*\.(?:[a-z0-9\-]+\.)?ovh\.net$/', $value) === 1;
    }

    /**
     * Encrypt a secret with WHMCS' configured encryption (cc_encryption_hash),
     * so VPS credentials are never stored in plaintext. Returns '' on failure.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || !function_exists('localAPI')) {
            return '';
        }
        $r = localAPI('EncryptPassword', ['password2' => $plain]);
        return (string) ($r['password'] ?? '');
    }

    /**
     * Decrypt a secret produced by {@see encrypt()}. Returns '' on failure.
     */
    public static function decrypt(string $cipher): string
    {
        if ($cipher === '' || !function_exists('localAPI')) {
            return '';
        }
        $r = localAPI('DecryptPassword', ['password2' => $cipher]);
        return (string) ($r['password'] ?? '');
    }

    /**
     * Load the client-area language strings for a given WHMCS language,
     * falling back to English.
     *
     * @return array<string, string>
     */
    public static function lang(string $language = 'english'): array
    {
        $_LANG = [];
        $safe = strtolower(preg_replace('/[^a-z\-]/i', '', $language) ?? '');
        // WHMCS ships 'portuguese' (historically Brazilian) and 'portuguese-pt'
        // (European). This module only provides European Portuguese, so map ANY
        // Portuguese locale to portuguese-pt - otherwise a client whose WHMCS
        // language is 'portuguese' would fall through to English in the panel.
        if (strncmp($safe, 'portuguese', 10) === 0) {
            $safe = 'portuguese-pt';
        }
        $file = dirname(__DIR__) . '/lang/' . $safe . '.php';
        if ($safe === '' || !is_file($file)) {
            $file = dirname(__DIR__) . '/lang/english.php';
        }
        require $file;
        return $_LANG;
    }

    /**
     * Write to the WHMCS module log and to our task log table (best-effort).
     *
     * @param mixed $request
     * @param mixed $response
     */
    public static function log(string $action, $request = null, $response = null, bool $success = true, ?int $serviceId = null): void
    {
        if (function_exists('logModuleCall')) {
            logModuleCall('ovhvps', $action, $request, $response);
        }
        try {
            Database::logTask($serviceId, $action, $request, $response, $success);
        } catch (\Throwable $e) {
            // best-effort: never let logging break the caller.
        }
    }

    /**
     * The WHMCS billing cycle of the service being provisioned (e.g. "Monthly",
     * "Annually"). Reads tblhosting first (most reliable), then the service model
     * passed in $params. Returns '' when unknown.
     *
     * @param array<string, mixed> $params server-module params
     */
    public static function billingCycle(array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId > 0) {
            $cycle = Capsule::table('tblhosting')->where('id', $serviceId)->value('billingcycle');
            if (is_string($cycle) && $cycle !== '') {
                return $cycle;
            }
        }
        $model = $params['model'] ?? null;
        if (is_object($model) && is_string($model->billingcycle ?? null) && $model->billingcycle !== '') {
            return (string) $model->billingcycle;
        }
        return '';
    }

    /**
     * Reconstruct a server-module-style $params array for a service from the
     * WHMCS tables, decrypting the server password. Used by the cron and the
     * cancellation hook, which run outside the normal module call context.
     *
     * @return array<string, mixed>|null Null if the service is not an ovhvps product.
     */
    public static function paramsForService(int $serviceId): ?array
    {
        self::init();

        $hosting = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$hosting) {
            return null;
        }
        $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $hosting->packageid)->first();
        if (!$product || $product->servertype !== 'ovhvps') {
            return null;
        }
        $server = \WHMCS\Database\Capsule::table('tblservers')->where('id', $hosting->server)->first();

        $password = '';
        if ($server && $server->password !== '' && function_exists('localAPI')) {
            $decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
            $password = $decrypted['password'] ?? '';
        }

        $params = [
            'serviceid' => $serviceId,
            'pid' => (int) $hosting->packageid,
            'userid' => (int) $hosting->userid,
            'domain' => (string) $hosting->domain,
            'serverhostname' => $server->hostname ?? '',
            'serverusername' => $server->username ?? '',
            'serverpassword' => $password,
            'serveraccesshash' => $server->accesshash ?? '',
        ];
        for ($i = 1; $i <= 24; $i++) {
            $col = 'configoption' . $i;
            $params[$col] = $product->{$col} ?? '';
        }

        return $params;
    }

    /**
     * Reconstruct module params for a PRODUCT (no service), resolving the OVH
     * credentials from the first server in the product's server group. Used by
     * the availability/stock refresh, which runs per product in the cron.
     *
     * @return array<string, mixed>|null Null if not an ovhvps product / no server.
     */
    public static function paramsForProduct(int $pid): ?array
    {
        self::init();

        $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->first();
        if (!$product || $product->servertype !== 'ovhvps') {
            return null;
        }

        $serverId = \WHMCS\Database\Capsule::table('tblservergroupsrel')
            ->where('groupid', $product->servergroup)
            ->value('serverid');
        $server = $serverId
            ? \WHMCS\Database\Capsule::table('tblservers')->where('id', $serverId)->first()
            : null;
        if (!$server) {
            return null;
        }

        $password = '';
        if ($server->password !== '' && function_exists('localAPI')) {
            $decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
            $password = $decrypted['password'] ?? '';
        }

        $params = [
            'pid' => $pid,
            'serverhostname' => $server->hostname ?? '',
            'serverusername' => $server->username ?? '',
            'serverpassword' => $password,
            'serveraccesshash' => $server->accesshash ?? '',
        ];
        for ($i = 1; $i <= 24; $i++) {
            $col = 'configoption' . $i;
            $params[$col] = $product->{$col} ?? '';
        }

        return $params;
    }
}
