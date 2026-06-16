<?php

/**
 * WHMCS hooks for the ovhvps module:
 *  - CancellationRequest: when a customer requests cancellation, schedule the
 *    OVH deletion at term end immediately (so OVH stops billing the reseller),
 *    without waiting for WHMCS to terminate the service.
 *  - AfterCronJob: reconcile pending provisioning (resolve delivered serviceNames).
 */

use OvhVps\Cron;
use OvhVps\Helper;
use OvhVps\Lifecycle;
use OvhVps\OvhClient;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

add_hook('CancellationRequest', 1, static function (array $vars): void {
    $serviceId = (int) ($vars['relid'] ?? 0);
    if ($serviceId <= 0) {
        return;
    }

    $params = Helper::paramsForService($serviceId);
    if ($params === null) {
        return; // not an ovhvps service
    }

    if (!Helper::bool(Helper::cfg($params)['auto_delete_on_terminate'] ?: 'on')) {
        return;
    }

    $serviceName = Lifecycle::serviceName($params);
    if ($serviceName === null) {
        return;
    }

    try {
        Lifecycle::scheduleDeleteAtExpiration(OvhClient::fromParams($params), $serviceName);
        \OvhVps\Database::upsertServer($serviceId, ['delete_at_expiration' => 1]);
        Helper::log('hook:cancellation', ['service' => $serviceName], 'scheduled deleteAtExpiration', true, $serviceId);
    } catch (\Throwable $e) {
        Helper::log('hook:cancellation', ['service' => $serviceName], $e->getMessage(), false, $serviceId);
    }
});

add_hook('AfterCronJob', 1, static function (): void {
    try {
        Cron::run();
    } catch (\Throwable $e) {
        Helper::log('hook:cron', null, $e->getMessage(), false);
    }
});

/**
 * Inject the tiny stock script on the order/cart pages so out-of-stock
 * datacenters (marked "… - Fora de Stock") render as disabled options.
 */
add_hook('ClientAreaHeaderOutput', 1, static function (array $vars): string {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (stripos($script, 'cart.php') === false) {
        return '';
    }
    $base = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
    return '<script src="' . $base . '/modules/servers/ovhvps/assets/js/ovhvps.stock.js"></script>';
});

/**
 * Load the admin service-panel JS on the admin "Products/Services" page.
 * Done here (not via a <script> tag inside AdminServicesTabFields) because
 * WHMCS 9.x may sanitise inline scripts out of that tab's HTML.
 */
add_hook('AdminAreaFooterOutput', 1, static function (array $vars): string {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (stripos($script, 'clientsservices.php') === false) {
        return '';
    }
    $base = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
    return '<script src="' . $base . '/modules/servers/ovhvps/assets/js/ovhvps.admin.js"></script>';
});

/**
 * Inject the "generate configurable options" panel on the admin product-edit
 * page (configproducts.php), only for ovhvps products. The button posts
 * admin_setup_options (pid) to ajax.php to sync the catalog + generate options
 * without needing a service to exist.
 */
add_hook('AdminAreaFooterOutput', 1, static function (array $vars): string {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (stripos($script, 'configproducts.php') === false) {
        return '';
    }
    $pid = (int) ($_GET['id'] ?? 0);
    if ($pid <= 0) {
        return '';
    }
    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->first();
    if (!$product || ($product->servertype ?? '') !== 'ovhvps') {
        return '';
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['ovhvps_csrf'])) {
        $_SESSION['ovhvps_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = htmlspecialchars((string) $_SESSION['ovhvps_csrf'], ENT_QUOTES);

    $hasOptions = \WHMCS\Database\Capsule::table('tblproductconfiggroups')
        ->where('name', 'OVH VPS #' . $pid)->exists() ? '1' : '0';

    $base = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
    $ajax = htmlspecialchars($base . '/modules/servers/ovhvps/ajax.php', ENT_QUOTES);
    $js = htmlspecialchars($base . '/modules/servers/ovhvps/assets/js/ovhvps.product.js', ENT_QUOTES);

    return '<div id="ovhvps_product" data-pid="' . $pid . '" data-csrf="' . $csrf
        . '" data-ajax="' . $ajax . '" data-has-options="' . $hasOptions
        . '" style="margin:15px 0;padding:12px;border:1px solid #ddd;border-radius:6px;max-width:680px">'
        . '<h4 style="margin-top:0">OVH VPS - configurable options</h4>'
        . '<p style="color:#666;margin:4px 0 8px">Generate the Configurable Options (Operating System, Datacenter and extras) for this product from the OVH catalog. Set the Server Group and the VPS Plan Code, then save the product before clicking.</p>'
        . '<button type="button" class="btn btn-primary" id="ovhvps_gen_btn">Generate OVH options</button>'
        . '<span id="ovhvps_product_status" style="margin-left:10px"></span>'
        . '</div>'
        . '<script src="' . $js . '"></script>';
});
