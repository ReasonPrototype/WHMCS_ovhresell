<?php

/**
 * WHMCS hooks for the ovhvps module:
 *  - CancellationRequest: when a customer requests cancellation, pause OVH
 *    auto-renewal now (so OVH stops billing the reseller); the VPS keeps
 *    running until WHMCS terminates it and then lapses at the end of the term.
 *  - AfterCronJob: reconcile pending provisioning (resolve delivered serviceNames).
 */

use OvhVps\Availability;
use OvhVps\Cron;
use OvhVps\Database;
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

    // Both "Immediate" and "End of Billing Period" requests do the same thing at
    // request time: pause OVH auto-renewal so the reseller stops being billed.
    // We never stop the VPS here (the customer keeps access until WHMCS actually
    // terminates); immediate deletion is the admin's manual step. The type is
    // logged only. This runs at REQUEST time because WHMCS has no "admin accepted"
    // hook, and pausing renewal is safe and reversible (Resume Auto-Renew).
    $type = (string) ($vars['type'] ?? '');
    try {
        Lifecycle::stopRenewal(OvhClient::fromParams($params), $serviceName);
        Database::upsertServer($serviceId, ['delete_at_expiration' => 1]);
        Helper::log('hook:cancellation', ['service' => $serviceName, 'type' => $type], 'auto-renew paused', true, $serviceId);
    } catch (\Throwable $e) {
        Helper::log('hook:cancellation', ['service' => $serviceName, 'type' => $type], $e->getMessage(), false, $serviceId);
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
 * Before the customer pays, re-check the chosen datacenter + OS against OVH
 * live, so they cannot buy a combination that ran out since the hourly cron.
 * Fail-safe: on any OVH error fall back to the cached matrix, and on any local
 * parsing error do not block (the checkout dry-run is the final guard).
 */
add_hook('ShoppingCartValidateCheckout', 1, static function (array $vars): array {
    $errors = [];
    $cartProducts = $_SESSION['cart']['products'] ?? [];
    if (!is_array($cartProducts) || $cartProducts === []) {
        return $errors;
    }
    $lang = Helper::lang((string) ($_SESSION['Language'] ?? 'english'));
    $message = (string) ($lang['stock_oos_combo']
        ?? 'The selected datacenter and operating system are no longer in stock together.');

    foreach ($cartProducts as $cartProduct) {
        try {
            $pid = (int) ($cartProduct['pid'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->first();
            if (!$product || ($product->servertype ?? '') !== 'ovhvps') {
                continue;
            }
            $params = Helper::paramsForProduct($pid);
            if ($params === null) {
                continue;
            }
            $cfg = Helper::cfg($params);
            $planCode = trim($cfg['plan_code']);
            if ($planCode === '') {
                continue;
            }
            $endpoint = Helper::endpointKey($params);
            $subsidiary = strtoupper(trim($cfg['subsidiary'] ?: 'PT'));

            $chosen = Availability::cartSelection($pid, (array) ($cartProduct['configoptions'] ?? []));
            $dc = $chosen['datacenter'];
            if ($dc === null) {
                continue; // no datacenter chosen yet; nothing to validate
            }
            $os = $chosen['os'];

            try {
                $client = OvhClient::fromParams($params);
                $matrix = Availability::parseDatacenterMatrix(
                    $client->get('/vps/order/rule/datacenter', ['planCode' => $planCode, 'ovhSubsidiary' => $subsidiary])
                );
                $ok = Availability::matrixAllows($matrix, $dc, $os);
            } catch (\Throwable $e) {
                Helper::log('hook:checkout-live', ['pid' => $pid, 'dc' => $dc, 'os' => $os], $e->getMessage(), false);
                $ok = Availability::isDatacenterOrderable($endpoint, $subsidiary, $planCode, $dc, $os);
            }
            if (!$ok) {
                $errors[] = $message;
            }
        } catch (\Throwable $e) {
            Helper::log('hook:checkout', ['pid' => $cartProduct['pid'] ?? null], $e->getMessage(), false);
        }
    }
    return array_values(array_unique($errors));
});

/**
 * Inject the tiny stock script on the order/cart pages so out-of-stock
 * datacenters (marked "... - Fora de Stock") render as disabled options, and
 * embed the per-OS availability matrix so the OS and Datacenter dropdowns can
 * react to each other.
 */
add_hook('ClientAreaHeaderOutput', 1, static function (array $vars): string {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (stripos($script, 'cart.php') === false) {
        return '';
    }
    $base = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
    $payload = json_encode(
        \OvhVps\Availability::cartStockData(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return '<script type="application/json" id="ovhvps-stock">' . $payload . '</script>'
        . '<script src="' . $base . '/modules/servers/ovhvps/assets/js/ovhvps.stock.js"></script>';
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
