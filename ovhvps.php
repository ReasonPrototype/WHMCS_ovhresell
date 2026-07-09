<?php

/**
 * OVHcloud VPS server module for WHMCS: one VPS product where the customer
 * picks the OS image, including the free Docker and n8n application images.
 *
 * Install: drop this folder into /modules/servers/ovhvps and run
 * "composer install" inside it. Configure an OVH API key on the server
 * (Username = Application Key, Password = Application Secret,
 * Access Hash = Consumer Key, Hostname = endpoint e.g. ovh-eu).
 *
 * @see https://eu.api.ovh.com/console/
 */

use OvhVps\Actions;
use OvhVps\AdminActions;
use OvhVps\ConfigOptions;
use OvhVps\Database;
use OvhVps\Helper;
use OvhVps\Lifecycle;
use OvhVps\OvhClient;
use OvhVps\Provisioning;
use OvhVps\Upgrade;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

/**
 * Module metadata.
 *
 * @return array<string, mixed>
 */
function ovhvps_MetaData(): array
{
    return [
        'DisplayName' => 'OVHcloud VPS',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Open VPS Console',
    ];
}

/**
 * Product Module Settings (shown on the product's Module Settings tab).
 *
 * @return array<string, array<string, string>>
 */
function ovhvps_ConfigOptions(): array
{
    return Helper::configFields();
}

/**
 * Validate the OVH credentials configured on the server (GET /me).
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function ovhvps_TestConnection(array $params): array
{
    try {
        $me = OvhClient::fromParams($params)->me();
        $who = $me['nichandle'] ?? ($me['customerCode'] ?? 'unknown');
        return [
            'success' => true,
            'error' => '',
            'message' => 'Connected to OVH account ' . $who,
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Provision a VPS by ordering it through the OVH cart (autoPay) and storing the
 * resulting serviceName. Idempotent: a service that already has an OVH order is
 * never re-ordered.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or an error message.
 */
function ovhvps_CreateAccount(array $params): string
{
    try {
        $result = Provisioning::order($params);
        return $result['success'] ? 'success' : $result['message'];
    } catch (\Throwable $e) {
        Helper::log('CreateAccount', ['serviceid' => $params['serviceid'] ?? null], $e->getMessage(), false);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Upgrade an existing VPS when the customer changes product/options in WHMCS.
 * Adds new paid options (backup, snapshot, disk, IP, Veeam) via OVH
 * cartServiceOption and upgrades the model in place via OVH order/upgrade. Both
 * are add-only and gated (orderability/availableUpgrade + dry-run). Option
 * removals and model downgrades are refused with a clear message.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or an error message.
 */
function ovhvps_ChangePackage(array $params): string
{
    try {
        $result = Upgrade::apply($params);
        return $result['success'] ? 'success' : $result['message'];
    } catch (\Throwable $e) {
        Helper::log('ChangePackage', ['serviceid' => $params['serviceid'] ?? null], $e->getMessage(), false);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Suspend = power off the VPS.
 *
 * @param array<string, mixed> $params
 */
function ovhvps_SuspendAccount(array $params): string
{
    return Lifecycle::suspend($params);
}

/**
 * Unsuspend = power on the VPS.
 *
 * @param array<string, mixed> $params
 */
function ovhvps_UnsuspendAccount(array $params): string
{
    return Lifecycle::unsuspend($params);
}

/**
 * Terminate = schedule OVH deletion at term end (automatic, no token), stop the
 * VPS now, and optionally request immediate hard-termination. Runs for both
 * admin-initiated termination and processed customer cancellations.
 *
 * @param array<string, mixed> $params
 */
function ovhvps_TerminateAccount(array $params): string
{
    return Lifecycle::terminate($params);
}

/**
 * Set the customer-chosen root password over SSH (see Lifecycle::changePassword);
 * the password is never stored and a confirmation email is sent.
 *
 * @param array<string, mixed> $params
 */
function ovhvps_ChangePassword(array $params): string
{
    return Lifecycle::changePassword($params);
}

/**
 * Render the customer's VPS management page.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function ovhvps_ClientArea(array $params): array
{
    try {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['ovhvps_csrf'])) {
            $_SESSION['ovhvps_csrf'] = bin2hex(random_bytes(16));
        }

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $language = (string) ($params['clientsdetails']['language'] ?? 'english');
        $lang = Helper::lang($language);
        $serviceName = Lifecycle::serviceName($params);
        $info = [];
        $error = '';

        if ($serviceName === null) {
            $error = $lang['provisioning_msg'];
        } else {
            try {
                $info = Actions::info(OvhClient::fromParams($params), $serviceName);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // n8n is just an OS image: detect it from the installed OS to show the
        // n8n access tab.
        $server = Database::getServer($serviceId) ?? [];
        $os = (string) ($server['os'] ?? ($info['os'] ?? ''));
        $isN8n = stripos($os, 'n8n') !== false;

        // Access credentials for the overview panel (any image, once bootstrapped).
        $access = [
            'state' => (string) ($server['access_state'] ?? ''),
            'user' => (string) ($server['root_user'] ?? ''),
        ];

        // Client-area feature gating: Snapshot and Veeam are paid OVH options
        // with no free tier, so their tabs are locked when the customer did not
        // buy the matching configurable option. Automated Backup ships a free
        // Standard tier and is never gated. The unlock button sends the customer
        // to this exact service's configure-options page.
        $pid = (int) ($params['pid'] ?? 0);
        $entitlements = [
            'snapshot' => ConfigOptions::isFamilyPurchased($serviceId, $pid, 'snapshot'),
            'veeam' => ConfigOptions::isFamilyPurchased($serviceId, $pid, 'veeam'),
        ];
        $upgradeUrl = rtrim((string) ($params['systemurl'] ?? ''), '/')
            . '/upgrade.php?type=configoptions&id=' . $serviceId;

        return [
            'templatefile' => 'templates/overview',
            'vars' => [
                'ovh' => $info,
                'serviceid' => $serviceId,
                'isN8n' => $isN8n,
                'hostname' => (string) ($params['domain'] ?? ''),
                'os' => $os,
                'access' => $access,
                'entitlements' => $entitlements,
                'upgradeUrl' => $upgradeUrl,
                'csrf' => $_SESSION['ovhvps_csrf'],
                'lang' => $lang,
                'error' => $error,
            ],
        ];
    } catch (\Throwable $e) {
        return [
            'templatefile' => 'templates/error',
            'vars' => ['error' => $e->getMessage()],
        ];
    }
}

/**
 * Quick action button shown on the client service page.
 *
 * @return array<string, string>
 */
function ovhvps_ClientAreaCustomButtonArray(): array
{
    return [
        'Reboot' => 'reboot',
    ];
}

/**
 * Handler for the "Reboot" client button.
 *
 * @param array<string, mixed> $params
 */
function ovhvps_reboot(array $params): string
{
    $result = Actions::dispatch('reboot', $params);
    return $result['status'] === 'Error' ? 'Error: ' . $result['message'] : 'success';
}

/**
 * Admin service panel: mirrors the client actions and adds admin-only controls
 * (sync catalog, generate options, retry provisioning, set serviceName, confirm
 * termination, toggle auto-delete). Rendered on the admin's service page.
 *
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function ovhvps_AdminServicesTabFields(array $params): array
{
    try {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['ovhvps_csrf'])) {
            $_SESSION['ovhvps_csrf'] = bin2hex(random_bytes(16));
        }
        $csrf = $_SESSION['ovhvps_csrf'];
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $server = Database::getServer($serviceId) ?? [];
        $order = Provisioning::getOrder($serviceId) ?? [];

        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $serviceName = $esc($server['service_name'] ?? '');
        $state = $esc($server['state'] ?? 'unknown');
        $orderId = $esc($order['order_id'] ?? '');
        $orderStatus = $esc($order['status'] ?? '');
        $cost = $esc($order['expected_cost'] ?? '');
        $currency = $esc($order['currency'] ?? '');
        $renewState = !empty($server['delete_at_expiration']) ? 'paused (cancelling)' : 'active';
        $webRoot = $esc(rtrim((string) ($params['systemurl'] ?? ''), '/'));

        $html = <<<HTML
<div id="ovhvps_admin" data-serviceid="{$serviceId}" data-csrf="{$csrf}" data-ajax="{$webRoot}/modules/servers/ovhvps/ajax.php">
  <table class="table" style="max-width:640px">
    <tr><td><b>OVH serviceName</b></td><td>{$serviceName}</td></tr>
    <tr><td><b>State</b></td><td>{$state}</td></tr>
    <tr><td><b>Order</b></td><td>{$orderId} ({$orderStatus})</td></tr>
    <tr><td><b>OVH cost (your price)</b></td><td>{$cost} {$currency}</td></tr>
    <tr><td><b>Auto-renew</b></td><td>{$renewState}</td></tr>
  </table>
  <div id="ovhvps_admin_status" style="margin:8px 0;"></div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
    <button class="btn btn-default" data-admin="admin_sync_catalog">Sync Catalog</button>
    <button class="btn btn-default" data-admin="admin_generate_options">Generate Config Options</button>
    <button class="btn btn-default" data-admin="admin_retry_provision">Retry Provisioning</button>
    <button class="btn btn-default" data-admin="admin_resync_info">Refresh Info</button>
    <button class="btn btn-default" data-admin="admin_check_availability">Check Stock</button>
    <button class="btn btn-default" data-admin="admin_check_watchdog">Check Catalog Changes</button>
    <button class="btn btn-default" data-admin="admin_clear_image_cache">Clear Image Cache</button>
    <button class="btn btn-default" data-admin="admin_stop_renew">Pause Renewal</button>
    <button class="btn btn-default" data-admin="admin_resume_renew">Resume Auto-Renew</button>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
    <input type="text" id="ovhvps_admin_sn" class="form-control" style="width:240px" placeholder="set serviceName manually">
    <button class="btn btn-default" data-admin="admin_set_servicename" data-field="ovhvps_admin_sn" data-name="service_name">Set</button>
    <input type="text" id="ovhvps_admin_token" class="form-control" style="width:240px" placeholder="termination token (from OVH email)">
    <button class="btn btn-warning" data-admin="admin_confirm_termination" data-field="ovhvps_admin_token" data-name="token">Confirm Termination</button>
  </div>
  <p style="margin-top:10px;color:#888">Power, console, reinstall, snapshots and the other VPS actions are available on the client area page for this service.</p>
</div>
HTML;
        // NOTE: the admin JS is loaded via the AdminAreaFooterOutput hook (see
        // hooks.php), not a <script> tag here - WHMCS 9.x may strip inline
        // scripts from AdminServicesTabFields output.

        return ['OVH VPS Management' => $html];
    } catch (\Throwable $e) {
        return ['OVH VPS Management' => 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)];
    }
}

/**
 * Admin quick button: refresh the cached service info from OVH.
 *
 * @return array<string, string>
 */
function ovhvps_AdminCustomButtonArray(): array
{
    return [
        'Refresh From OVH' => 'adminRefresh',
    ];
}

/**
 * @param array<string, mixed> $params
 */
function ovhvps_adminRefresh(array $params): string
{
    $result = AdminActions::dispatch('admin_resync_info', $params);
    return $result['status'] === 'Error' ? 'Error: ' . $result['message'] : 'success';
}
