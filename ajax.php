<?php

/**
 * Secure AJAX endpoint for client-area (and admin) VPS actions.
 *
 * Security: bootstraps WHMCS for the session, enforces a per-session CSRF token,
 * and verifies the logged-in client owns the service (admins bypass ownership).
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ovhvpsWhmcsInit = __DIR__ . '/../../../init.php';
if (is_file($ovhvpsWhmcsInit)) {
    require_once $ovhvpsWhmcsInit;
}

use OvhVps\Actions;
use OvhVps\AdminActions;
use OvhVps\Helper;
use WHMCS\Database\Capsule;

header('Content-Type: application/json');

/**
 * @return never
 */
function ovhvps_ajax_deny(string $message, int $code = 403): void
{
    http_response_code($code);
    echo json_encode(['status' => 'Error', 'message' => $message]);
    exit;
}

if (!defined('WHMCS')) {
    ovhvps_ajax_deny('WHMCS not initialised.', 500);
}

$action = (string) ($_POST['action'] ?? '');
$csrf = (string) ($_POST['csrf'] ?? '');

if ($action === '') {
    ovhvps_ajax_deny('Missing action.', 400);
}

// CSRF: token issued by ovhvps_ClientArea / the admin panels, stored in session.
if (empty($_SESSION['ovhvps_csrf']) || !hash_equals((string) $_SESSION['ovhvps_csrf'], $csrf)) {
    ovhvps_ajax_deny('Invalid security token. Reload the page and try again.');
}

// Prefer the documented WHMCS 9.x auth API; fall back to legacy session keys.
if (class_exists('\WHMCS\Authentication\CurrentUser')) {
    $currentUser = new \WHMCS\Authentication\CurrentUser();
    $isAdmin = $currentUser->isAuthenticatedAdmin();
    $clientObj = $currentUser->client();
    $clientUid = $clientObj ? (int) $clientObj->id : 0;
} else {
    $clientUid = (int) ($_SESSION['uid'] ?? 0);
    $isAdmin = !empty($_SESSION['adminid']);
}

// Product-context admin action (no service needed) - used by the product config page.
if ($action === 'admin_setup_options') {
    if (!$isAdmin) {
        ovhvps_ajax_deny('Administrator access required.');
    }
    $pid = (int) ($_POST['pid'] ?? 0);
    if ($pid <= 0) {
        ovhvps_ajax_deny('Missing product id.', 400);
    }
    $params = Helper::paramsForProduct($pid);
    if ($params === null) {
        ovhvps_ajax_deny('Not an OVH VPS product, or the Server Group / Plan Code is missing.', 400);
    }
    echo json_encode(AdminActions::dispatch($action, $params, $_POST));
    exit;
}

// Service-context actions (client + admin).
$serviceId = (int) ($_POST['service_id'] ?? 0);
if ($serviceId <= 0) {
    ovhvps_ajax_deny('Missing service_id.', 400);
}

$hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
if (!$hosting) {
    ovhvps_ajax_deny('Service not found.', 404);
}
if (!$isAdmin && ($clientUid <= 0 || (int) $hosting->userid !== $clientUid)) {
    ovhvps_ajax_deny('You do not have access to this service.');
}

$params = Helper::paramsForService($serviceId);
if ($params === null) {
    ovhvps_ajax_deny('Not an OVH VPS service.', 400);
}

if (strncmp($action, 'admin_', 6) === 0) {
    if (!$isAdmin) {
        ovhvps_ajax_deny('Administrator access required.');
    }
    $result = AdminActions::dispatch($action, $params, $_POST);
} else {
    $result = Actions::dispatch($action, $params, $_POST);
}
echo json_encode($result);
