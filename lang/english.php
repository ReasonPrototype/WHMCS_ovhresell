<?php

/**
 * English strings for the ovhvps module client area.
 * Loaded via OvhVps\Helper::lang(); each key is exposed to the template as
 * {$lang.key}.
 */

$_LANG['overview'] = 'Overview';
$_LANG['console'] = 'Console';
$_LANG['reinstall'] = 'Reinstall OS';
$_LANG['snapshots'] = 'Snapshots';
$_LANG['rescue'] = 'Rescue';
$_LANG['backups'] = 'Backups';
$_LANG['storage'] = 'Storage';
$_LANG['network'] = 'Network';
$_LANG['upgrade'] = 'Upgrade';
$_LANG['graphs'] = 'Graphs';
$_LANG['n8n'] = 'n8n';

$_LANG['power_on'] = 'Power On';
$_LANG['power_off'] = 'Power Off';
$_LANG['reboot'] = 'Reboot';
$_LANG['open_console'] = 'Open Console';
$_LANG['reinstall_btn'] = 'Reinstall';
$_LANG['create_snapshot'] = 'Create Snapshot';
$_LANG['revert'] = 'Revert';
$_LANG['delete'] = 'Delete';
$_LANG['boot_rescue'] = 'Boot Rescue Mode';
$_LANG['boot_normal'] = 'Boot Normal Mode';
$_LANG['reinstall_n8n'] = 'Reinstall n8n';
$_LANG['save'] = 'Save';
$_LANG['add'] = 'Add';

$_LANG['display_name'] = 'Display Name';
$_LANG['status'] = 'Status';
$_LANG['name'] = 'Name';
$_LANG['ip'] = 'IP';
$_LANG['ipv6'] = 'IPv6';
$_LANG['hostname'] = 'Hostname';
$_LANG['datacenter'] = 'Datacenter';
$_LANG['vcore'] = 'vCore';
$_LANG['memory'] = 'Memory (GB)';
$_LANG['disk'] = 'Disk (GB)';
// Storage tab section headings.
$_LANG['storage_vps_disk'] = 'VPS disk';
$_LANG['storage_additional_disks'] = 'Additional disks';
$_LANG['storage_total'] = 'Total storage';
$_LANG['operating_system'] = 'Operating System';
$_LANG['login_user'] = 'Username';
$_LANG['login_password'] = 'Password';
$_LANG['access_hint'] = 'Use these in the Console tab, or over SSH.';
$_LANG['access_preparing'] = 'We are preparing your access. This page will show your login shortly.';
$_LANG['access_emailed'] = 'Your password was sent by email. Use Change Password to set a new one.';
$_LANG['ssh_key'] = 'SSH public key (optional)';
$_LANG['ssh_key_hint'] = 'Paste your SSH public key (e.g. from PuTTYgen) to enable key-based login over SSH and SFTP. Recommended for PuTTY/FileZilla access.';

$_LANG['erase_warning'] = 'This erases all data on the VPS.';
$_LANG['provisioning_msg'] = 'Your VPS is still being provisioned. Please check back shortly.';

$_LANG['stock_oos_combo'] = 'The selected datacenter and operating system are no longer in stock together. Please pick another datacenter or another operating system.';

// --- Client-area pane text (template) ---
$_LANG['console_intro'] = 'Open a VNC console session to your VPS in the browser.';
$_LANG['snapshots_intro'] = 'A snapshot is a point-in-time copy you can roll back to.';
$_LANG['backups_automated'] = 'Automated Backup';
$_LANG['backups_veeam'] = 'Veeam Backup';
$_LANG['backups_ftp'] = 'Backup FTP';
$_LANG['network_ips_title'] = 'IP addresses & Reverse DNS';
$_LANG['network_secondary_dns'] = 'Secondary DNS';
$_LANG['n8n_intro'] = 'n8n comes pre-installed on this VPS (OVH image). Open the editor in your browser to create the owner account on first visit.';
$_LANG['loading'] = 'Loading…';

// --- Client-area JS strings (injected into window.ovhvps.lang) ---
$_LANG['js_working'] = 'Working on your request…';
$_LANG['js_failed'] = 'The action failed.';
$_LANG['js_in_progress'] = 'In progress…';
$_LANG['js_done'] = 'Done.';
$_LANG['js_network_error'] = 'Network error. Please try again.';
$_LANG['js_network_error_short'] = 'Network error.';
$_LANG['js_unavailable'] = 'Unavailable.';
$_LANG['js_not_configured'] = 'Not configured.';
$_LANG['js_confirm_generic'] = 'Are you sure you want to proceed?';
$_LANG['js_console_opened'] = 'Console opened below.';
$_LANG['js_confirm_reinstall'] = 'This erases ALL data on the VPS. Continue?';
$_LANG['js_confirm_reinstall_n8n'] = 'This ERASES ALL DATA and reinstalls n8n from scratch. Continue?';
$_LANG['js_no_snapshot'] = 'No snapshot exists yet.';
$_LANG['js_snapshot'] = 'Snapshot';
$_LANG['js_present'] = 'present';
$_LANG['js_automated_backup'] = 'Automated backup:';
$_LANG['js_enabled'] = 'enabled';
$_LANG['js_not_enabled'] = 'not enabled';
$_LANG['js_veeam_not_enabled'] = 'Veeam not enabled.';
$_LANG['js_ftp_not_enabled'] = 'Backup FTP not enabled.';
$_LANG['js_no_additional_disks'] = 'No additional disks.';
$_LANG['js_id'] = 'ID';
$_LANG['js_size'] = 'Size (GB)';
$_LANG['js_state'] = 'State';
$_LANG['js_type'] = 'Type';
$_LANG['js_no_ips'] = 'No IPs.';
$_LANG['js_ip'] = 'IP';
$_LANG['js_reverse_dns'] = 'Reverse DNS';
$_LANG['js_no_dns'] = 'No secondary DNS domains.';
$_LANG['js_domain'] = 'Domain';
$_LANG['js_remove'] = 'Remove';
$_LANG['js_unknown'] = 'unknown';
$_LANG['js_n8n_intro'] = 'Open n8n in your browser and create your owner account on the first visit.';
$_LANG['js_n8n_open'] = 'Open n8n';
$_LANG['js_n8n_url'] = 'n8n URL';
$_LANG['js_n8n_server_ip'] = 'Server IP';
$_LANG['js_n8n_port_note'] = 'Default n8n port is 5678. If you enabled HTTPS or a reverse proxy on the image, use that address instead.';
$_LANG['js_n8n_provisioning'] = 'n8n is still being provisioned. Please check back shortly.';

// --- Action result messages (returned by the server, shown in the panel) ---
$_LANG['msg_no_vps'] = 'This service has no VPS yet (still provisioning?).';
$_LANG['msg_reboot_requested'] = 'Reboot requested.';
$_LANG['msg_start_requested'] = 'Start requested.';
$_LANG['msg_stop_requested'] = 'Stop requested.';
$_LANG['msg_snapshot_creating'] = 'Snapshot creation started.';
$_LANG['msg_snapshot_reverting'] = 'Reverting to snapshot.';
$_LANG['msg_snapshot_deleted'] = 'Snapshot deleted.';
$_LANG['msg_backup_restoring'] = 'Restoring from automated backup.';
$_LANG['msg_veeam_restoring'] = 'Restoring from Veeam restore point.';
$_LANG['msg_reverse_updated'] = 'Reverse DNS updated.';
$_LANG['msg_dns_added'] = 'Secondary DNS domain added.';
$_LANG['msg_dns_removed'] = 'Secondary DNS domain removed.';
$_LANG['msg_use_reinstall_n8n'] = 'Use the "Reinstall n8n" button for this service.';
$_LANG['msg_no_image'] = 'No image selected.';
$_LANG['msg_os_not_available'] = 'That operating system is not available for your plan.';
$_LANG['msg_reinstall_started'] = 'Reinstall started. Your new access details will be emailed once the VPS is back up.';
$_LANG['msg_n8n_only'] = 'This action is only available for n8n services.';
$_LANG['msg_no_n8n_image'] = 'No n8n image is currently available for this VPS.';
$_LANG['msg_reinstall_n8n_started'] = 'Reinstalling n8n. All data will be wiped; n8n will be fresh in a few minutes.';

// --- Change Password result messages (WHMCS native page, translated server-side) ---
$_LANG['msg_pw_no_service'] = 'no serviceName for this service.';
$_LANG['msg_pw_no_password'] = 'no new password provided.';
$_LANG['msg_pw_n8n'] = 'n8n is web-only and has no root password.';
$_LANG['msg_pw_not_ready'] = 'this VPS is not ready for a password change yet. Reinstall the OS from the client area first.';
$_LANG['msg_pw_failed'] = 'could not set the password (the VPS may be starting). Try again shortly.';
