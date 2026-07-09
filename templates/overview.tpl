{*
    OVH VPS - client area management page.
    The n8n tab is additive: it appears when the installed OS image is n8n;
    every other tab is always present regardless of the image.
    All actions POST to ajax.php (CSRF + ownership enforced server-side).
*}
<script>
    window.ovhvps = {
        ajaxUrl: "{$WEB_ROOT}/modules/servers/ovhvps/ajax.php",
        serviceid: {$serviceid|intval},
        csrf: "{$csrf}",
        systemDisk: {$ovh.disk|default:0|intval},
        upgradeUrl: "{$upgradeUrl|default:''|escape:'javascript'}",
        entitlements: { snapshot: {if $entitlements.snapshot}true{else}false{/if}, veeam: {if $entitlements.veeam}true{else}false{/if} },
        lang: {
            working: "{$lang.js_working|default:'Working on your request…'|escape:'javascript'}",
            failed: "{$lang.js_failed|default:'The action failed.'|escape:'javascript'}",
            in_progress: "{$lang.js_in_progress|default:'In progress…'|escape:'javascript'}",
            done: "{$lang.js_done|default:'Done.'|escape:'javascript'}",
            network_error: "{$lang.js_network_error|default:'Network error. Please try again.'|escape:'javascript'}",
            network_error_short: "{$lang.js_network_error_short|default:'Network error.'|escape:'javascript'}",
            unavailable: "{$lang.js_unavailable|default:'Unavailable.'|escape:'javascript'}",
            not_configured: "{$lang.js_not_configured|default:'Not configured.'|escape:'javascript'}",
            loading: "{$lang.loading|default:'Loading…'|escape:'javascript'}",
            confirm_generic: "{$lang.js_confirm_generic|default:'Are you sure you want to proceed?'|escape:'javascript'}",
            console_opened: "{$lang.js_console_opened|default:'Console opened below.'|escape:'javascript'}",
            confirm_reinstall: "{$lang.js_confirm_reinstall|default:'This erases ALL data on the VPS. Continue?'|escape:'javascript'}",
            no_snapshot: "{$lang.js_no_snapshot|default:'No snapshot exists yet.'|escape:'javascript'}",
            snapshot: "{$lang.js_snapshot|default:'Snapshot'|escape:'javascript'}",
            present: "{$lang.js_present|default:'present'|escape:'javascript'}",
            snapshot_creating: "{$lang.js_snapshot_creating|default:'Snapshot is being created; this can take a minute. This panel refreshes automatically.'|escape:'javascript'}",
            option_locked: "{$lang.js_option_locked|default:'This feature is a paid option that is not part of your current plan. Add it to unlock this tab.'|escape:'javascript'}",
            option_buy: "{$lang.js_option_buy|default:'Add this option'|escape:'javascript'}",
            automated_backup: "{$lang.js_automated_backup|default:'Automated backup:'|escape:'javascript'}",
            enabled: "{$lang.js_enabled|default:'enabled'|escape:'javascript'}",
            not_enabled: "{$lang.js_not_enabled|default:'not enabled'|escape:'javascript'}",
            veeam_not_enabled: "{$lang.js_veeam_not_enabled|default:'Veeam not enabled.'|escape:'javascript'}",
            ftp_not_enabled: "{$lang.js_ftp_not_enabled|default:'Backup FTP not enabled.'|escape:'javascript'}",
            restore: "{$lang.js_restore|default:'Restore'|escape:'javascript'}",
            restore_point: "{$lang.js_restore_point|default:'Restore point'|escape:'javascript'}",
            confirm_restore: "{$lang.js_confirm_restore|default:'Restoring overwrites ALL current data on the VPS with the selected backup. Continue?'|escape:'javascript'}",
            no_restore_points: "{$lang.js_no_restore_points|default:'No restore points available.'|escape:'javascript'}",
            no_additional_disks: "{$lang.js_no_additional_disks|default:'No additional disks.'|escape:'javascript'}",
            id: "{$lang.js_id|default:'ID'|escape:'javascript'}",
            size: "{$lang.js_size|default:'Size (GB)'|escape:'javascript'}",
            state: "{$lang.js_state|default:'State'|escape:'javascript'}",
            type: "{$lang.js_type|default:'Type'|escape:'javascript'}",
            no_ips: "{$lang.js_no_ips|default:'No IPs.'|escape:'javascript'}",
            ip: "{$lang.js_ip|default:'IP'|escape:'javascript'}",
            reverse_dns: "{$lang.js_reverse_dns|default:'Reverse DNS'|escape:'javascript'}",
            no_dns: "{$lang.js_no_dns|default:'No secondary DNS domains.'|escape:'javascript'}",
            domain: "{$lang.js_domain|default:'Domain'|escape:'javascript'}",
            save: "{$lang.save|default:'Save'|escape:'javascript'}",
            remove: "{$lang.js_remove|default:'Remove'|escape:'javascript'}",
            unknown: "{$lang.js_unknown|default:'unknown'|escape:'javascript'}",
            n8n_intro: "{$lang.js_n8n_intro|default:'Open n8n in your browser and create your owner account on the first visit.'|escape:'javascript'}",
            n8n_open: "{$lang.js_n8n_open|default:'Open n8n'|escape:'javascript'}",
            n8n_url: "{$lang.js_n8n_url|default:'n8n URL'|escape:'javascript'}",
            n8n_server_ip: "{$lang.js_n8n_server_ip|default:'Server IP'|escape:'javascript'}",
            n8n_port_note: "{$lang.js_n8n_port_note|default:'Default n8n port is 5678. If you enabled HTTPS or a reverse proxy on the image, use that address instead.'|escape:'javascript'}",
            n8n_provisioning: "{$lang.js_n8n_provisioning|default:'n8n is still being provisioned. Please check back shortly.'|escape:'javascript'}",
            msg_no_vps: "{$lang.msg_no_vps|default:'This service has no VPS yet (still provisioning?).'|escape:'javascript'}",
            msg_reboot_requested: "{$lang.msg_reboot_requested|default:'Reboot requested.'|escape:'javascript'}",
            msg_start_requested: "{$lang.msg_start_requested|default:'Start requested.'|escape:'javascript'}",
            msg_stop_requested: "{$lang.msg_stop_requested|default:'Stop requested.'|escape:'javascript'}",
            msg_snapshot_creating: "{$lang.msg_snapshot_creating|default:'Snapshot creation started.'|escape:'javascript'}",
            msg_snapshot_reverting: "{$lang.msg_snapshot_reverting|default:'Reverting to snapshot.'|escape:'javascript'}",
            msg_snapshot_deleted: "{$lang.msg_snapshot_deleted|default:'Snapshot deleted.'|escape:'javascript'}",
            msg_backup_restoring: "{$lang.msg_backup_restoring|default:'Restoring from automated backup.'|escape:'javascript'}",
            msg_veeam_restoring: "{$lang.msg_veeam_restoring|default:'Restoring from Veeam restore point.'|escape:'javascript'}",
            msg_reverse_updated: "{$lang.msg_reverse_updated|default:'Reverse DNS updated.'|escape:'javascript'}",
            msg_dns_added: "{$lang.msg_dns_added|default:'Secondary DNS domain added.'|escape:'javascript'}",
            msg_dns_removed: "{$lang.msg_dns_removed|default:'Secondary DNS domain removed.'|escape:'javascript'}",
            msg_no_image: "{$lang.msg_no_image|default:'No image selected.'|escape:'javascript'}",
            msg_os_not_available: "{$lang.msg_os_not_available|default:'That operating system is not available for your plan.'|escape:'javascript'}",
            msg_reinstall_started: "{$lang.msg_reinstall_started|default:'Reinstall started. Your new access details will be emailed once the VPS is back up.'|escape:'javascript'}",
            msg_reinstall_started_windows: "{$lang.msg_reinstall_started_windows|default:'Reinstall started. Windows takes a while to install; our team will deliver your new login credentials separately.'|escape:'javascript'}",
            msg_n8n_only: "{$lang.msg_n8n_only|default:'This action is only available for n8n services.'|escape:'javascript'}"
        }
    };
</script>

<style>
    .ovhvps-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .ovhvps-actions button { min-width: 120px; }
    .ovhvps-status { display: none; margin: 12px 0; padding: 10px 14px; border-radius: 6px; }
    .ovhvps-status.process { display: block; background: #eef4ff; color: #1c4587; }
    .ovhvps-status.success { display: block; background: #d1e7dd; color: #0f5132; }
    .ovhvps-status.error   { display: block; background: #f8d7da; color: #842029; }
    .ovhvps-info { width: 100%; }
    .ovhvps-info th { width: 40%; color: #555; font-weight: 600; }
    #ovhvps_novnc { width: 100%; height: 600px; border: 1px solid #ddd; border-radius: 6px; }
    .ovhvps-tab-pane { display: none; padding-top: 20px; }
    .ovhvps-tab-pane.active { display: block; }
</style>

{if $error}
    <div class="alert alert-warning">{$error}</div>
{/if}

<div class="ovhvps-status" id="ovhvps_status"></div>

<ul class="nav nav-tabs" id="ovhvps_tabs" role="tablist">
    <li class="active"><a href="#" data-tab="overview">{$lang.overview|default:'Overview'}</a></li>
    <li><a href="#" data-tab="console">{$lang.console|default:'Console'}</a></li>
    <li><a href="#" data-tab="reinstall">{$lang.reinstall|default:'Reinstall OS'}</a></li>
    <li><a href="#" data-tab="snapshots">{$lang.snapshots|default:'Snapshots'}</a></li>
    <li><a href="#" data-tab="backups">{$lang.backups|default:'Backups'}</a></li>
    <li><a href="#" data-tab="storage">{$lang.storage|default:'Storage'}</a></li>
    <li><a href="#" data-tab="network">{$lang.network|default:'Network'}</a></li>
    {if $isN8n}<li><a href="#" data-tab="n8n">{$lang.n8n|default:'n8n'}</a></li>{/if}
</ul>

<div class="ovhvps-tab-pane active" data-pane="overview">
    <div class="ovhvps-actions">
        <button class="btn btn-success" data-action="start">{$lang.power_on|default:'Power On'}</button>
        <button class="btn btn-warning" data-action="stop" data-confirm="1">{$lang.power_off|default:'Power Off'}</button>
        <button class="btn btn-primary" data-action="reboot" data-confirm="1">{$lang.reboot|default:'Reboot'}</button>
    </div>
    {if $access.state == 'ready'}
        <table class="table ovhvps-info" style="margin-bottom:16px;">
            <tbody>
                <tr><th>{$lang.login_user|default:'Username'}</th><td>{$access.user}</td></tr>
            </tbody>
        </table>
        <p class="text-muted">{$lang.access_emailed|default:'Your password was sent by email. Use Change Password to set a new one.'}</p>
    {elseif $access.state == 'manual'}
        {* Terminal Windows state: credentials come from the team by hand, so never show the "preparing" spinner text. *}
        <div class="alert alert-info">{$lang.access_manual|default:'Your VPS is online. Your login credentials are delivered separately by our team.'}</div>
    {elseif $access.state != '' && $access.state != 'failed'}
        <div class="alert alert-info">{$lang.access_preparing|default:'We are preparing your access. This page will show your login shortly.'}</div>
    {/if}
    <table class="table table-striped ovhvps-info">
        <tbody>
            <tr><th>{$lang.hostname|default:'Hostname'}</th><td>{$hostname}</td></tr>
            <tr><th>{$lang.status|default:'Status'}</th><td id="ovhvps_state">{$ovh.state}</td></tr>
            <tr><th>{$lang.operating_system|default:'Operating System'}</th><td>{$os}</td></tr>
            <tr><th>{$lang.ip|default:'IP'}</th><td>{$ovh.ip}</td></tr>
            {if $ovh.ipv6}<tr><th>{$lang.ipv6|default:'IPv6'}</th><td>{$ovh.ipv6}</td></tr>{/if}
            <tr><th>{$lang.datacenter|default:'Datacenter'}</th><td>{$ovh.zone}</td></tr>
            <tr><th>{$lang.vcore|default:'vCore'}</th><td>{$ovh.vcore}</td></tr>
            <tr><th>{$lang.memory|default:'Memory (GB)'}</th><td>{$ovh.memoryLimit}</td></tr>
            <tr><th>{$lang.disk|default:'Disk (GB)'}</th><td>{$ovh.disk}</td></tr>
        </tbody>
    </table>
</div>

<div class="ovhvps-tab-pane" data-pane="console">
    <p>{$lang.console_intro|default:'Open a VNC console session to your VPS in the browser.'}</p>
    <button class="btn btn-primary" id="ovhvps_open_console">{$lang.open_console|default:'Open Console'}</button>
    <div style="margin-top:15px;"><iframe id="ovhvps_novnc" src="about:blank"></iframe></div>
</div>

<div class="ovhvps-tab-pane" data-pane="reinstall">
    <p>{$lang.reinstall|default:'Reinstall OS'}. <strong>{$lang.erase_warning|default:'This erases all data on the VPS.'}</strong></p>
    <div class="form-group">
        <label for="ovhvps_image">{$lang.operating_system|default:'Operating System'}</label>
        <select id="ovhvps_image" class="form-control" style="max-width:360px;"></select>
    </div>
    <button class="btn btn-danger" id="ovhvps_reinstall" data-confirm="1">{$lang.reinstall_btn|default:'Reinstall'}</button>
</div>

<div class="ovhvps-tab-pane" data-pane="snapshots">
    <p>{$lang.snapshots_intro|default:'A snapshot is a point-in-time copy you can roll back to.'}</p>
    <div id="ovhvps_snapshot_info" style="margin-bottom:12px;"></div>
    <div class="ovhvps-actions">
        <button class="btn btn-primary" data-action="snapshot_create">{$lang.create_snapshot|default:'Create Snapshot'}</button>
        <button class="btn btn-warning" data-action="snapshot_revert" data-confirm="1">{$lang.revert|default:'Revert'}</button>
        <button class="btn btn-danger" data-action="snapshot_delete" data-confirm="1">{$lang.delete|default:'Delete'}</button>
    </div>
</div>

<div class="ovhvps-tab-pane" data-pane="backups">
    <h4>{$lang.backups_automated|default:'Automated Backup'}</h4>
    <div id="ovhvps_backup_panel">{$lang.loading|default:'Loading…'}</div>
    <h4 style="margin-top:20px;">{$lang.backups_veeam|default:'Veeam Backup'}</h4>
    <div id="ovhvps_veeam_panel">{$lang.loading|default:'Loading…'}</div>
    <h4 style="margin-top:20px;">{$lang.backups_ftp|default:'Backup FTP'}</h4>
    <div id="ovhvps_ftp_panel">{$lang.loading|default:'Loading…'}</div>
</div>

<div class="ovhvps-tab-pane" data-pane="storage">
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
        <div style="border:1px solid #ddd;border-radius:6px;padding:10px 14px;">
            <div style="color:#888;font-size:12px;">{$lang.storage_vps_disk|default:'System disk'}</div>
            <div style="font-size:20px;font-weight:600;">{$ovh.disk|default:'-'} GB</div>
        </div>
        <div style="border:1px solid #ddd;border-radius:6px;padding:10px 14px;">
            <div style="color:#888;font-size:12px;">{$lang.storage_total|default:'Total storage'}</div>
            <div style="font-size:20px;font-weight:600;" id="ovhvps_disks_total">{$ovh.disk|default:'-'} GB</div>
        </div>
    </div>
    <h4>{$lang.storage_additional_disks|default:'Additional disks'}</h4>
    <div id="ovhvps_disks_panel">{$lang.loading|default:'Loading…'}</div>
</div>

<div class="ovhvps-tab-pane" data-pane="network">
    <h4>{$lang.network_ips_title|default:'IP addresses &amp; Reverse DNS'}</h4>
    <div id="ovhvps_ips_panel">{$lang.loading|default:'Loading…'}</div>
    <h4 style="margin-top:20px;">{$lang.network_secondary_dns|default:'Secondary DNS'}</h4>
    <div id="ovhvps_dns_panel">{$lang.loading|default:'Loading…'}</div>
    <form id="ovhvps_dns_add" class="form-inline" style="margin-top:10px;">
        <input type="text" class="form-control" name="domain" placeholder="domain.tld">
        <input type="text" class="form-control" name="ip" placeholder="IP">
        <button type="submit" class="btn btn-primary">{$lang.add|default:'Add'}</button>
    </form>
</div>

{if $isN8n}
<div class="ovhvps-tab-pane" data-pane="n8n">
    <p>{$lang.n8n_intro|default:'n8n comes pre-installed on this VPS (OVH image). Open the editor in your browser to create the owner account on first visit.'}</p>
    <div id="ovhvps_n8n_panel">{$lang.loading|default:'Loading…'}</div>
</div>
{/if}

<script src="{$WEB_ROOT}/modules/servers/ovhvps/assets/js/ovhvps.client.js"></script>
