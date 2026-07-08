<?php

/**
 * Portuguese (pt-PT) strings for the ovhvps module client area.
 * Loaded via OvhVps\Helper::lang(); each key is exposed as {$lang.key}.
 */

$_LANG['overview'] = 'Resumo';
$_LANG['console'] = 'Consola';
$_LANG['reinstall'] = 'Reinstalar SO';
$_LANG['snapshots'] = 'Snapshots';
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
$_LANG['access_manual'] = 'O seu VPS está online. Os seus dados de acesso são entregues em separado pela nossa equipa.';
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
$_LANG['js_no_snapshot'] = 'Ainda não existe nenhum snapshot.';
$_LANG['js_snapshot'] = 'Snapshot';
$_LANG['js_present'] = 'presente';
$_LANG['js_snapshot_creating'] = 'O snapshot está a ser criado; pode demorar cerca de um minuto. Este painel atualiza automaticamente.';
$_LANG['js_snapshot_option_required'] = 'Os snapshots requerem a opção Snapshot, que ainda não está ativada neste VPS. Contacte o suporte para a ativar.';
$_LANG['js_automated_backup'] = 'Backup automático:';
$_LANG['js_enabled'] = 'ativado';
$_LANG['js_not_enabled'] = 'não ativado';
$_LANG['js_veeam_not_enabled'] = 'Veeam não ativado.';
$_LANG['js_ftp_not_enabled'] = 'Backup FTP não ativado.';
$_LANG['js_restore'] = 'Restaurar';
$_LANG['js_restore_point'] = 'Ponto de restauro';
$_LANG['js_confirm_restore'] = 'Restaurar substitui TODOS os dados atuais do VPS pelo backup selecionado. Continuar?';
$_LANG['js_no_restore_points'] = 'Sem pontos de restauro disponíveis.';
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

// --- Action result messages (returned by the server, shown in the panel) ---
$_LANG['msg_no_vps'] = 'Este serviço ainda não tem VPS (ainda em aprovisionamento?).';
$_LANG['msg_reboot_requested'] = 'Reinício pedido.';
$_LANG['msg_start_requested'] = 'Arranque pedido.';
$_LANG['msg_stop_requested'] = 'Paragem pedida.';
$_LANG['msg_snapshot_creating'] = 'Criação de snapshot iniciada.';
$_LANG['msg_snapshot_reverting'] = 'A reverter para o snapshot.';
$_LANG['msg_snapshot_deleted'] = 'Snapshot apagado.';
$_LANG['msg_backup_restoring'] = 'A restaurar a partir do backup automático.';
$_LANG['msg_veeam_restoring'] = 'A restaurar a partir do ponto de restauro Veeam.';
$_LANG['msg_reverse_updated'] = 'DNS inverso atualizado.';
$_LANG['msg_dns_added'] = 'Domínio de DNS secundário adicionado.';
$_LANG['msg_dns_removed'] = 'Domínio de DNS secundário removido.';
$_LANG['msg_no_image'] = 'Nenhuma imagem selecionada.';
$_LANG['msg_os_not_available'] = 'Esse sistema operativo não está disponível para o seu plano.';
$_LANG['msg_reinstall_started'] = 'Reinstalação iniciada. Os seus novos dados de acesso serão enviados por email assim que o VPS voltar.';
$_LANG['msg_reinstall_started_windows'] = 'Reinstalação iniciada. O Windows demora algum tempo a instalar; a nossa equipa irá entregar os seus novos dados de acesso em separado.';
$_LANG['msg_n8n_only'] = 'Esta ação só está disponível para serviços n8n.';

// --- Change Password result messages (WHMCS native page, translated server-side) ---
$_LANG['msg_pw_no_service'] = 'sem serviceName para este serviço.';
$_LANG['msg_pw_no_password'] = 'nenhuma nova palavra-passe fornecida.';
$_LANG['msg_pw_not_ready'] = 'este VPS ainda não está pronto para alterar a palavra-passe. Reinstale o SO na área de cliente primeiro.';
$_LANG['msg_pw_windows'] = 'as alterações de palavra-passe em Windows são tratadas pela nossa equipa. Abra um ticket de suporte.';
$_LANG['msg_pw_failed'] = 'não foi possível definir a palavra-passe (o VPS pode estar a arrancar). Tente novamente daqui a pouco.';
