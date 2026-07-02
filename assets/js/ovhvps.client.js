/* OVH VPS client-area actions. Posts to ajax.php with the session CSRF token. */
(function ($) {
    "use strict";

    if (!window.ovhvps) {
        return;
    }
    var cfg = window.ovhvps;

    // Translate a server-returned message key via the injected lang map; pass
    // dynamic strings (e.g. raw OVH errors) through unchanged.
    function tmsg(m) { return (m && cfg.lang && cfg.lang[m]) || m; }

    function setStatus(kind, message) {
        var $s = $("#ovhvps_status");
        $s.removeClass("process success error").addClass(kind).text(message);
    }

    function call(action, extra) {
        var data = $.extend({
            action: action,
            service_id: cfg.serviceid,
            csrf: cfg.csrf
        }, extra || {});
        return $.ajax({ url: cfg.ajaxUrl, method: "POST", dataType: "json", data: data });
    }

    function run(action, extra) {
        setStatus("process", cfg.lang.working);
        return call(action, extra).done(function (res) {
            if (!res || res.status === "Error") {
                setStatus("error", (res && tmsg(res.message)) || cfg.lang.failed);
                return;
            }
            if (res.status === "Processing") {
                setStatus("process", tmsg(res.message) || cfg.lang.in_progress);
            } else {
                setStatus("success", tmsg(res.message) || cfg.lang.done);
            }
        }).fail(function () {
            setStatus("error", cfg.lang.network_error);
        });
    }

    // --- tabs ---
    $(document).on("click", "#ovhvps_tabs a", function (e) {
        e.preventDefault();
        var tab = $(this).data("tab");
        $("#ovhvps_tabs li").removeClass("active");
        $(this).parent().addClass("active");
        $(".ovhvps-tab-pane").removeClass("active");
        $('.ovhvps-tab-pane[data-pane="' + tab + '"]').addClass("active");
        if (tab === "reinstall") { loadImages(); }
        if (tab === "snapshots") { loadSnapshot(); }
        if (tab === "n8n") { loadN8n(); }
        if (tab === "backups") { loadBackups(); }
        if (tab === "storage") { loadDisks(); }
        if (tab === "network") { loadNetwork(); }
    });

    // Render a flat object as a two-column table; nested values are stringified.
    function kvTable(obj) {
        if (!obj || typeof obj !== "object") { return $("<p>").text(cfg.lang.not_configured); }
        // Hide OVH-internal identifiers so the customer never sees the .ovh.net name.
        var hiddenKeys = { serviceResourceName: 1, serviceName: 1 };
        var $t = $('<table class="table table-striped"></table>');
        $.each(obj, function (k, v) {
            if (hiddenKeys[k]) { return; }
            if (v !== null && typeof v === "object") { v = JSON.stringify(v); }
            v = String(v);
            if (v.indexOf(".ovh.net") !== -1) { return; }
            $t.append($("<tr>").append($("<th>").text(k), $("<td>").text(v)));
        });
        return $t;
    }

    function loadInto(selector, action, render) {
        $(selector).text(cfg.lang.loading);
        call(action).done(function (res) {
            if (!res || res.status === "Error") {
                $(selector).text((res && tmsg(res.message)) || cfg.lang.unavailable);
                return;
            }
            $(selector).empty().append(render(res.data));
        }).fail(function () { $(selector).text(cfg.lang.network_error_short); });
    }

    // --- generic data-action buttons ---
    $(document).on("click", "[data-action]", function () {
        var action = $(this).data("action");
        if ($(this).data("confirm") && !window.confirm(cfg.lang.confirm_generic)) {
            return;
        }
        run(action).done(function (res) {
            if (res && res.status === "OK") {
                if (action === "stop") { $("#ovhvps_state").text("stopped"); }
                if (action === "start") { $("#ovhvps_state").text("running"); }
                if (action === "snapshot_delete" || action === "snapshot_create") { loadSnapshot(); }
            }
        });
    });

    // --- console ---
    $(document).on("click", "#ovhvps_open_console", function () {
        run("console").done(function (res) {
            if (res && res.status === "OK" && res.data && res.data.url) {
                $("#ovhvps_novnc").attr("src", res.data.url);
                setStatus("success", cfg.lang.console_opened);
            }
        });
    });

    // --- reinstall ---
    function loadImages() {
        call("images").done(function (res) {
            if (!res || res.status !== "OK" || !res.data) { return; }
            var $sel = $("#ovhvps_image").empty();
            var list = res.data.available || [];
            $.each(list, function (i, img) {
                var id = img.id || img.imageId || img;
                var name = img.name || img.distribution || id;
                $sel.append($("<option>").val(id).text(name));
            });
        });
    }

    $(document).on("click", "#ovhvps_reinstall", function () {
        if (!window.confirm(cfg.lang.confirm_reinstall)) { return; }
        run("reinstall", { image_id: $("#ovhvps_image").val() });
    });

    // --- snapshots ---
    function loadSnapshot() {
        call("snapshot_list").done(function (res) {
            var html = cfg.lang.no_snapshot;
            if (res && res.status === "OK" && res.data && res.data.snapshot) {
                var s = res.data.snapshot;
                html = cfg.lang.snapshot + ": " + (s.description || s.creationDate || cfg.lang.present);
            }
            $("#ovhvps_snapshot_info").html(html);
        });
    }

    // --- backups / veeam / ftp ---
    function loadBackups() {
        loadInto("#ovhvps_backup_panel", "backup_status", function (d) {
            var $w = $("<div>");
            $w.append($("<p>").text(cfg.lang.automated_backup + " " + ((d && d.backup) ? cfg.lang.enabled : cfg.lang.not_enabled)));
            if (d && d.backup) { $w.append(kvTable(d.backup)); }
            return $w;
        });
        loadInto("#ovhvps_veeam_panel", "veeam_status", function (d) {
            return (d && d.veeam) ? kvTable(d.veeam) : $("<p>").text(cfg.lang.veeam_not_enabled);
        });
        loadInto("#ovhvps_ftp_panel", "ftp_status", function (d) {
            return d ? kvTable(d) : $("<p>").text(cfg.lang.ftp_not_enabled);
        });
    }

    // --- storage / disks ---
    function loadDisks() {
        loadInto("#ovhvps_disks_panel", "disks_list", function (list) {
            var extra = 0;
            if (list && list.length) {
                $.each(list, function (i, d) { extra += parseInt(d.size, 10) || 0; });
            }
            var total = (parseInt(cfg.systemDisk, 10) || 0) + extra;
            $("#ovhvps_disks_total").text(total + " GB");

            if (!list || !list.length) { return $("<p>").text(cfg.lang.no_additional_disks); }
            var $t = $('<table class="table table-striped"><thead><tr><th>' + cfg.lang.id + '</th><th>' + cfg.lang.size + '</th><th>' + cfg.lang.state + '</th><th>' + cfg.lang.type + '</th></tr></thead><tbody></tbody></table>');
            $.each(list, function (i, d) {
                $t.find("tbody").append(
                    $("<tr>").append(
                        $("<td>").text(d.id || ""),
                        $("<td>").text(d.size || d.bandwidth || ""),
                        $("<td>").text(d.state || ""),
                        $("<td>").text(d.type || "")
                    )
                );
            });
            return $t;
        });
    }

    // --- network: IPs + reverse DNS + secondary DNS ---
    function loadNetwork() {
        loadInto("#ovhvps_ips_panel", "ips_list", function (list) {
            if (!list || !list.length) { return $("<p>").text(cfg.lang.no_ips); }
            var $t = $('<table class="table"><thead><tr><th>' + cfg.lang.ip + '</th><th>' + cfg.lang.reverse_dns + '</th><th></th></tr></thead><tbody></tbody></table>');
            $.each(list, function (i, ip) {
                var addr = ip.ipAddress || ip;
                var $input = $('<input class="form-control input-sm">').val(ip.reverse || "");
                var $btn = $('<button class="btn btn-sm btn-default">' + cfg.lang.save + '</button>').on("click", function () {
                    run("reverse_set", { ip: addr, reverse: $input.val() });
                });
                $t.find("tbody").append($("<tr>").append(
                    $("<td>").text(addr),
                    $("<td>").append($input),
                    $("<td>").append($btn)
                ));
            });
            return $t;
        });
        loadInto("#ovhvps_dns_panel", "dns_list", function (list) {
            if (!list || !list.length) { return $("<p>").text(cfg.lang.no_dns); }
            var $t = $('<table class="table"><thead><tr><th>' + cfg.lang.domain + '</th><th></th></tr></thead><tbody></tbody></table>');
            $.each(list, function (i, dom) {
                var name = (dom && dom.domain) ? dom.domain : String(dom);
                var $btn = $('<button class="btn btn-sm btn-danger">' + cfg.lang.remove + '</button>').on("click", function () {
                    run("dns_remove", { domain: name }).done(loadNetwork);
                });
                $t.find("tbody").append($("<tr>").append($("<td>").text(name), $("<td>").append($btn)));
            });
            return $t;
        });
    }

    $(document).on("submit", "#ovhvps_dns_add", function (e) {
        e.preventDefault();
        var domain = $(this).find('[name="domain"]').val();
        var ip = $(this).find('[name="ip"]').val();
        run("dns_add", { domain: domain, ip: ip }).done(loadNetwork);
    });

    // --- n8n access info (shown when the installed OS is an n8n image) ---
    function loadN8n() {
        call("n8n_status").done(function (res) {
            if (res && res.status === "OK" && res.data && res.data.url) {
                var d = res.data;
                $("#ovhvps_n8n_panel").html(
                    "<p>" + cfg.lang.n8n_intro + "</p>" +
                    '<p><a class="btn btn-primary" href="' + d.url + '" target="_blank" rel="noopener">' + cfg.lang.n8n_open + '</a></p>' +
                    "<table class='table'>" +
                    "<tr><td><b>" + cfg.lang.n8n_url + "</b></td><td><a href='" + d.url + "' target='_blank' rel='noopener'>" + d.url + "</a></td></tr>" +
                    "<tr><td><b>" + cfg.lang.n8n_server_ip + "</b></td><td>" + (d.ip || "") + "</td></tr>" +
                    "<tr><td><b>" + cfg.lang.state + "</b></td><td>" + (d.state || cfg.lang.unknown) + "</td></tr>" +
                    "</table>" +
                    "<p class='text-muted'>" + cfg.lang.n8n_port_note + "</p>"
                );
            } else {
                $("#ovhvps_n8n_panel").text((res && res.message) || cfg.lang.n8n_provisioning);
            }
        });
    }
})(jQuery);
