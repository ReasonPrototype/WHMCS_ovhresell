<?php

/**
 * Portuguese (pt-PT) strings for the ovhvps module client area.
 * Loaded via OvhVps\Helper::lang(); each key is exposed as {$lang.key}.
 */

$_LANG['overview'] = 'Resumo';
$_LANG['console'] = 'Consola';
$_LANG['reinstall'] = 'Reinstalar SO';
$_LANG['snapshots'] = 'Snapshots';
$_LANG['rescue'] = 'Rescue';
$_LANG['backups'] = 'Backups';
$_LANG['storage'] = 'Armazenamento';
$_LANG['network'] = 'Rede';
$_LANG['upgrade'] = 'Upgrade';
$_LANG['graphs'] = 'Gráficos';
$_LANG['n8n'] = 'n8n';

$_LANG['power_on'] = 'Ligar';
$_LANG['power_off'] = 'Desligar';
$_LANG['reboot'] = 'Reiniciar';
$_LANG['open_console'] = 'Abrir Consola';
$_LANG['reinstall_btn'] = 'Reinstalar';
$_LANG['create_snapshot'] = 'Criar Snapshot';
$_LANG['revert'] = 'Reverter';
$_LANG['delete'] = 'Apagar';
$_LANG['boot_rescue'] = 'Arrancar em Rescue';
$_LANG['boot_normal'] = 'Arrancar Normal';
$_LANG['reinstall_n8n'] = 'Reinstalar n8n';
$_LANG['save'] = 'Guardar';
$_LANG['add'] = 'Adicionar';

$_LANG['display_name'] = 'Nome';
$_LANG['status'] = 'Estado';
$_LANG['name'] = 'Nome do Servidor';
$_LANG['ip'] = 'IP';
$_LANG['ipv6'] = 'IPv6';
$_LANG['hostname'] = 'Nome de anfitriao';
$_LANG['datacenter'] = 'Datacenter';
$_LANG['vcore'] = 'vCore';
$_LANG['memory'] = 'Memória (GB)';
$_LANG['disk'] = 'Disco (GB)';
// Storage tab section headings.
$_LANG['storage_vps_disk'] = 'Disco da VPS';
$_LANG['storage_additional_disks'] = 'Discos adicionais';
$_LANG['storage_total'] = 'Armazenamento total';
$_LANG['operating_system'] = 'Sistema Operativo';
$_LANG['login_user'] = 'Utilizador';
$_LANG['login_password'] = 'Palavra-passe';
$_LANG['access_hint'] = 'Use estes dados no separador Consola, ou por SSH.';
$_LANG['access_preparing'] = 'Estamos a preparar o seu acesso. Esta página mostrará o seu login em breve.';
$_LANG['access_emailed'] = 'A sua password foi enviada por email. Use Alterar Password para definir uma nova.';
$_LANG['ssh_key'] = 'Chave pública SSH (opcional)';
$_LANG['ssh_key_hint'] = 'Cole a sua chave pública SSH (por exemplo, do PuTTYgen) para ativar o acesso por SSH e SFTP. Recomendado para usar PuTTY/FileZilla.';

$_LANG['erase_warning'] = 'Isto apaga todos os dados do VPS.';
$_LANG['provisioning_msg'] = 'O seu VPS ainda está a ser aprovisionado. Volte daqui a pouco.';

$_LANG['stock_oos_combo'] = 'O datacenter e o sistema operativo escolhidos já não têm stock em conjunto. Escolha outro datacenter ou outro sistema operativo.';

// --- Client-area pane text (template) ---
$_LANG['console_intro'] = 'Abra uma sessão de consola VNC ao seu VPS no browser.';
$_LANG['snapshots_intro'] = 'Um snapshot é uma cópia num instante para onde pode reverter.';
$_LANG['backups_automated'] = 'Backup Automático';
$_LANG['backups_veeam'] = 'Backup Veeam';
$_LANG['backups_ftp'] = 'Backup FTP';
$_LANG['network_ips_title'] = 'Endereços IP e DNS Inverso';
$_LANG['network_secondary_dns'] = 'DNS Secundário';
$_LANG['n8n_intro'] = 'O n8n vem pré-instalado neste VPS (imagem OVH). Abra o editor no browser para criar a conta de proprietário na primeira visita.';
$_LANG['loading'] = 'A carregar…';

// --- Client-area JS strings (injected into window.ovhvps.lang) ---
$_LANG['js_working'] = 'A processar o seu pedido…';
$_LANG['js_failed'] = 'A ação falhou.';
$_LANG['js_in_progress'] = 'Em curso…';
$_LANG['js_done'] = 'Concluído.';
$_LANG['js_network_error'] = 'Erro de rede. Tente novamente.';
$_LANG['js_network_error_short'] = 'Erro de rede.';
$_LANG['js_unavailable'] = 'Indisponível.';
$_LANG['js_not_configured'] = 'Não configurado.';
$_LANG['js_confirm_generic'] = 'Tem a certeza que quer continuar?';
$_LANG['js_console_opened'] = 'Consola aberta abaixo.';
$_LANG['js_confirm_reinstall'] = 'Isto apaga TODOS os dados do VPS. Continuar?';
$_LANG['js_confirm_reinstall_n8n'] = 'Isto APAGA TODOS OS DADOS e reinstala o n8n de raiz. Continuar?';
$_LANG['js_no_snapshot'] = 'Ainda não existe nenhum snapshot.';
$_LANG['js_snapshot'] = 'Snapshot';
$_LANG['js_present'] = 'presente';
$_LANG['js_automated_backup'] = 'Backup automático:';
$_LANG['js_enabled'] = 'ativado';
$_LANG['js_not_enabled'] = 'não ativado';
$_LANG['js_veeam_not_enabled'] = 'Veeam não ativado.';
$_LANG['js_ftp_not_enabled'] = 'Backup FTP não ativado.';
$_LANG['js_no_additional_disks'] = 'Sem discos adicionais.';
$_LANG['js_id'] = 'ID';
$_LANG['js_size'] = 'Tamanho (GB)';
$_LANG['js_state'] = 'Estado';
$_LANG['js_type'] = 'Tipo';
$_LANG['js_no_ips'] = 'Sem IPs.';
$_LANG['js_ip'] = 'IP';
$_LANG['js_reverse_dns'] = 'DNS Inverso';
$_LANG['js_no_dns'] = 'Sem domínios de DNS secundário.';
$_LANG['js_domain'] = 'Domínio';
$_LANG['js_remove'] = 'Remover';
$_LANG['js_unknown'] = 'desconhecido';
$_LANG['js_n8n_intro'] = 'Abra o n8n no browser e crie a sua conta de proprietário na primeira visita.';
$_LANG['js_n8n_open'] = 'Abrir n8n';
$_LANG['js_n8n_url'] = 'URL do n8n';
$_LANG['js_n8n_server_ip'] = 'IP do servidor';
$_LANG['js_n8n_port_note'] = 'A porta padrão do n8n é 5678. Se ativou HTTPS ou um reverse proxy na imagem, use esse endereço.';
$_LANG['js_n8n_provisioning'] = 'O n8n ainda está a ser aprovisionado. Volte daqui a pouco.';
