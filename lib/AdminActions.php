<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Admin-only management actions for a service, invoked from the admin service
 * panel ({@see ovhvps_AdminServicesTabFields}) via ajax.php. Kept separate from
 * {@see Actions} so these privileged operations are never reachable by clients.
 */
class AdminActions
{
    /**
     * @param array<string, mixed> $params Reconstructed server-module params.
     * @param array<string, mixed> $input
     * @return array{status:string, message:string, data:mixed}
     */
    public static function dispatch(string $action, array $params, array $input = []): array
    {
        Helper::init();
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $cfg = Helper::cfg($params);
        $endpoint = Helper::endpointKey($params);
        $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'FR'));

        try {
            switch ($action) {
                case 'admin_sync_catalog':
                    $counts = Catalog::sync(OvhClient::fromParams($params), $endpoint, $subsidiary);
                    return self::ok('Catalog synced.', $counts);

                case 'admin_generate_options':
                    $planCode = trim($cfg['plan_code']);
                    if ($planCode === '') {
                        return self::err('Set the VPS Plan Code on the product first.');
                    }
                    $res = ConfigOptions::generate((int) ($params['pid'] ?? 0), $endpoint, $subsidiary, $planCode);
                    return self::ok('Configurable options generated.', $res);

                case 'admin_retry_provision':
                    $res = Provisioning::order($params);
                    return $res['success'] ? self::ok($res['message'], $res) : self::err($res['message']);

                case 'admin_set_servicename':
                    $name = trim((string) ($input['service_name'] ?? ''));
                    if ($name === '') {
                        return self::err('serviceName is required.');
                    }
                    Database::upsertServer($serviceId, ['service_name' => $name, 'state' => 'delivered']);
                    Capsule::table(Database::ORDERS)->where('service_id', $serviceId)->update(['status' => 'delivered']);
                    return self::ok('serviceName set to ' . $name . '.');

                case 'admin_confirm_termination':
                    $token = trim((string) ($input['token'] ?? ''));
                    if ($token === '') {
                        return self::err('Paste the termination token from the OVH email.');
                    }
                    $r = Lifecycle::confirmTermination($params, $token);
                    return $r['success'] ? self::ok($r['message']) : self::err($r['message']);

                case 'admin_toggle_autodelete':
                    $serviceName = Lifecycle::serviceName($params);
                    if ($serviceName === null) {
                        return self::err('No serviceName for this service.');
                    }
                    $client = OvhClient::fromParams($params);
                    if (!empty($input['enable'])) {
                        Lifecycle::scheduleDeleteAtExpiration($client, $serviceName);
                        Database::upsertServer($serviceId, ['delete_at_expiration' => 1]);
                        return self::ok('Deletion at expiration scheduled.');
                    }
                    Lifecycle::cancelDeleteAtExpiration($client, $serviceName);
                    Database::upsertServer($serviceId, ['delete_at_expiration' => 0]);
                    return self::ok('Deletion at expiration cancelled.');

                case 'admin_resync_info':
                    $serviceName = Lifecycle::serviceName($params);
                    if ($serviceName === null) {
                        return self::err('No serviceName yet.');
                    }
                    $info = Actions::info(OvhClient::fromParams($params), $serviceName);
                    Database::upsertServer($serviceId, [
                        'state' => $info['state'] ?? null,
                        'ip_main' => $info['ip'] ?? null,
                        'datacenter' => $info['zone'] ?? null,
                    ]);
                    return self::ok('Service info refreshed.', $info);

                case 'admin_check_availability':
                    $counts = Availability::refresh();
                    return self::ok('Availability refreshed: ' . $counts['inStock'] . ' in stock, ' . $counts['outOfStock'] . ' out of stock.', $counts);

                case 'admin_clear_image_cache':
                    // Drop the 24h cache of OVH reinstall images for this VPS so the
                    // next Reinstall tab open rebuilds it live (useful for testing).
                    $serviceName = Lifecycle::serviceName($params);
                    if ($serviceName === null) {
                        return self::err('No serviceName yet; nothing is cached.');
                    }
                    Database::forgetCache('images:' . $serviceName);
                    return self::ok('Reinstall image cache cleared; it rebuilds on the next Reinstall tab open.');

                case 'admin_order_info':
                    return self::ok('', [
                        'order' => Provisioning::getOrder($serviceId),
                        'server' => Database::getServer($serviceId),
                    ]);

                case 'admin_setup_options':
                    $planCode = trim($cfg['plan_code']);
                    if ($planCode === '') {
                        return self::err('Set the VPS Plan Code on the product first.');
                    }
                    Catalog::sync(OvhClient::fromParams($params), $endpoint, $subsidiary);
                    $res = ConfigOptions::generate((int) ($params['pid'] ?? 0), $endpoint, $subsidiary, $planCode);
                    return self::ok('Catalog synced and configurable options generated.', $res);

                default:
                    return self::err('Unknown admin action: ' . $action);
            }
        } catch (\Throwable $e) {
            Helper::log('admin:' . $action, ['service_id' => $serviceId], $e->getMessage(), false, $serviceId);
            return self::err($e->getMessage());
        }
    }

    /**
     * @param mixed $data
     * @return array{status:string, message:string, data:mixed}
     */
    private static function ok(string $message, $data = null): array
    {
        return ['status' => 'OK', 'message' => $message, 'data' => $data];
    }

    /**
     * @return array{status:string, message:string, data:mixed}
     */
    private static function err(string $message): array
    {
        return ['status' => 'Error', 'message' => $message, 'data' => null];
    }
}
