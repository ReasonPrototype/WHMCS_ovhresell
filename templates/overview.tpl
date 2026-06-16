{*
    OVH VPS / VPS-n8n - client area management page.
    All actions POST to ajax.php (CSRF + ownership enforced server-side).
*}
<script>
    window.ovhvps = {
        ajaxUrl: "{$WEB_ROOT}/modules/servers/ovhvps/ajax.php",
        serviceid: {$serviceid|intval},
        csrf: "{$csrf}"
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
    <li><a href="#" data-tab="rescue">{$lang.rescue|default:'Rescue'}</a></li>
    <li><a href="#" data-tab="backups">{$lang.backups|default:'Backups'}</a></li>
    <li><a href="#" data-tab="storage">{$lang.storage|default:'Storage'}</a></li>
    <li><a href="#" data-tab="network">{$lang.network|default:'Network'}</a></li>
    <li><a href="#" data-tab="upgrade">{$lang.upgrade|default:'Upgrade'}</a></li>
    <li><a href="#" data-tab="graphs">{$lang.graphs|default:'Graphs'}</a></li>
    {if $isN8n}<li><a href="#" data-tab="n8n">{$lang.n8n|default:'n8n'}</a></li>{/if}
</ul>

<div class="ovhvps-tab-pane active" data-pane="overview">
    <div class="ovhvps-actions">
        <button class="btn btn-success" data-action="start">{$lang.power_on|default:'Power On'}</button>
        <button class="btn btn-warning" data-action="stop" data-confirm="1">{$lang.power_off|default:'Power Off'}</button>
        <button class="btn btn-primary" data-action="reboot" data-confirm="1">{$lang.reboot|default:'Reboot'}</button>
    </div>
    <table class="table table-striped ovhvps-info">
        <tbody>
            <tr><th>{$lang.display_name|default:'Display Name'}</th><td>{$ovh.displayName}</td></tr>
            <tr><th>{$lang.status|default:'Status'}</th><td id="ovhvps_state">{$ovh.state}</td></tr>
            <tr><th>{$lang.name|default:'Name'}</th><td>{$ovh.name}</td></tr>
            <tr><th>{$lang.ip|default:'IP'}</th><td>{$ovh.ip}</td></tr>
            <tr><th>{$lang.datacenter|default:'Datacenter'}</th><td>{$ovh.zone}</td></tr>
            <tr><th>{$lang.vcore|default:'vCore'}</th><td>{$ovh.vcore}</td></tr>
            <tr><th>{$lang.memory|default:'Memory (GB)'}</th><td>{$ovh.memoryLimit}</td></tr>
            <tr><th>{$lang.disk|default:'Disk (GB)'}</th><td>{$ovh.disk}</td></tr>
        </tbody>
    </table>
</div>

<div class="ovhvps-tab-pane" data-pane="console">
    <p>Open a VNC console session to your VPS in the browser.</p>
    <button class="btn btn-primary" id="ovhvps_open_console">{$lang.open_console|default:'Open Console'}</button>
    <div style="margin-top:15px;"><iframe id="ovhvps_novnc" src="about:blank"></iframe></div>
</div>

<div class="ovhvps-tab-pane" data-pane="reinstall">
    <p>{$lang.reinstall|default:'Reinstall OS'}. <strong>{$lang.erase_warning|default:'This erases all data on the VPS.'}</strong></p>
    <div class="form-group">
        <label for="ovhvps_image">{$lang.operating_system|default:'Operating System'}</label>
        <select id="ovhvps_image" class="form-control" style="max-width:360px;"></select>
    </div>
    <div class="form-group">
        <label for="ovhvps_ssh_key">{$lang.ssh_key|default:'SSH public key (optional)'}</label>
        <textarea id="ovhvps_ssh_key" class="form-control" rows="3" style="max-width:560px;" placeholder="ssh-ed25519 AAAA... you@host"></textarea>
        <p class="text-muted" style="margin-top:6px;">{$lang.ssh_key_hint|default:'Paste your SSH public key (e.g. from PuTTYgen) to enable key-based login over SSH and SFTP. Recommended for PuTTY/FileZilla access.'}</p>
    </div>
    <button class="btn btn-danger" id="ovhvps_reinstall" data-confirm="1">{$lang.reinstall_btn|default:'Reinstall'}</button>
</div>

<div class="ovhvps-tab-pane" data-pane="snapshots">
    <p>A snapshot is a point-in-time copy you can roll back to.</p>
    <div id="ovhvps_snapshot_info" style="margin-bottom:12px;"></div>
    <div class="ovhvps-actions">
        <button class="btn btn-primary" data-action="snapshot_create">{$lang.create_snapshot|default:'Create Snapshot'}</button>
        <button class="btn btn-warning" data-action="snapshot_revert" data-confirm="1">{$lang.revert|default:'Revert'}</button>
        <button class="btn btn-danger" data-action="snapshot_delete" data-confirm="1">{$lang.delete|default:'Delete'}</button>
    </div>
</div>

<div class="ovhvps-tab-pane" data-pane="rescue">
    <p>Boot into a rescue environment for maintenance, then back to normal.</p>
    <div class="ovhvps-actions">
        <button class="btn btn-warning" data-action="rescue_on" data-confirm="1">{$lang.boot_rescue|default:'Boot Rescue Mode'}</button>
        <button class="btn btn-default" data-action="rescue_off" data-confirm="1">{$lang.boot_normal|default:'Boot Normal Mode'}</button>
    </div>
</div>

<div class="ovhvps-tab-pane" data-pane="backups">
    <h4>Automated Backup</h4>
    <div id="ovhvps_backup_panel">Loading…</div>
    <h4 style="margin-top:20px;">Veeam Backup</h4>
    <div id="ovhvps_veeam_panel">Loading…</div>
    <h4 style="margin-top:20px;">Backup FTP</h4>
    <div id="ovhvps_ftp_panel">Loading…</div>
</div>

<div class="ovhvps-tab-pane" data-pane="storage">
    <p>Disks attached to your VPS.</p>
    <div id="ovhvps_disks_panel">Loading…</div>
</div>

<div class="ovhvps-tab-pane" data-pane="network">
    <h4>IP addresses &amp; Reverse DNS</h4>
    <div id="ovhvps_ips_panel">Loading…</div>
    <h4 style="margin-top:20px;">Secondary DNS</h4>
    <div id="ovhvps_dns_panel">Loading…</div>
    <form id="ovhvps_dns_add" class="form-inline" style="margin-top:10px;">
        <input type="text" class="form-control" name="domain" placeholder="domain.tld">
        <input type="text" class="form-control" name="ip" placeholder="IP">
        <button type="submit" class="btn btn-primary">Add</button>
    </form>
</div>

<div class="ovhvps-tab-pane" data-pane="upgrade">
    <p>Plans you can upgrade this VPS to. Upgrades change billing and are applied by your provider.</p>
    <div id="ovhvps_upgrade_panel">Loading…</div>
</div>

<div class="ovhvps-tab-pane" data-pane="graphs">
    <p>Resource usage (best effort; OVH usage endpoints are being deprecated).</p>
    <div id="ovhvps_graphs_panel">Loading…</div>
</div>

{if $isN8n}
<div class="ovhvps-tab-pane" data-pane="n8n">
    <p>n8n comes pre-installed on this VPS (OVH image). Open the editor in your browser to create the owner account on first visit.</p>
    <div id="ovhvps_n8n_panel">Loading…</div>
</div>
{/if}

<script src="{$WEB_ROOT}/modules/servers/ovhvps/assets/js/ovhvps.client.js"></script>
