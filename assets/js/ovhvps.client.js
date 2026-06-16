/* OVH VPS client-area actions. Posts to ajax.php with the session CSRF token. */
(function ($) {
    "use strict";

    if (!window.ovhvps) {
        return;
    }
    var cfg = window.ovhvps;

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
        setStatus("process", "Working on your request…");
        return call(action, extra).done(function (res) {
            if (!res || res.status === "Error") {
                setStatus("error", (res && res.message) || "The action failed.");
                return;
            }
            if (res.status === "Processing") {
                setStatus("process", res.message || "In progress…");
            } else {
                setStatus("success", res.message || "Done.");
            }
        }).fail(function () {
            setStatus("error", "Network error. Please try again.");
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
        if (tab === "upgrade") { loadUpgrade(); }
        if (tab === "graphs") { loadGraphs(); }
    });

    function asJson(data) {
        return $("<pre>").css({ "max-height": "320px", "overflow": "auto" }).text(JSON.stringify(data, null, 2));
    }

    function loadInto(selector, action, render) {
        $(selector).text("Loading…");
        call(action).done(function (res) {
            if (!res || res.status === "Error") {
                $(selector).text((res && res.message) || "Unavailable.");
                return;
            }
            $(selector).empty().append(render(res.data));
        }).fail(function () { $(selector).text("Network error."); });
    }

    // --- generic data-action buttons ---
    $(document).on("click", "[data-action]", function () {
        var action = $(this).data("action");
        if ($(this).data("confirm") && !window.confirm("Are you sure you want to proceed?")) {
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
                setStatus("success", "Console opened below.");
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
        if (!window.confirm("This erases ALL data on the VPS. Continue?")) { return; }
        run("reinstall", { image_id: $("#ovhvps_image").val() });
    });

    // --- snapshots ---
    function loadSnapshot() {
        call("snapshot_list").done(function (res) {
            var html = "No snapshot exists yet.";
            if (res && res.status === "OK" && res.data && res.data.snapshot) {
                var s = res.data.snapshot;
                html = "Snapshot: " + (s.description || s.creationDate || "present");
            }
            $("#ovhvps_snapshot_info").html(html);
        });
    }

    // --- backups / veeam / ftp ---
    function loadBackups() {
        loadInto("#ovhvps_backup_panel", "backup_status", asJson);
        loadInto("#ovhvps_veeam_panel", "veeam_status", asJson);
        loadInto("#ovhvps_ftp_panel", "ftp_status", asJson);
    }

    // --- storage / disks ---
    function loadDisks() {
        loadInto("#ovhvps_disks_panel", "disks_list", function (list) {
            if (!list || !list.length) { return $("<p>").text("No additional disks."); }
            var $t = $('<table class="table table-striped"><thead><tr><th>ID</th><th>Size (GB)</th><th>State</th><th>Type</th></tr></thead><tbody></tbody></table>');
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
            if (!list || !list.length) { return $("<p>").text("No IPs."); }
            var $t = $('<table class="table"><thead><tr><th>IP</th><th>Reverse DNS</th><th></th></tr></thead><tbody></tbody></table>');
            $.each(list, function (i, ip) {
                var addr = ip.ipAddress || ip;
                var $input = $('<input class="form-control input-sm">').val(ip.reverse || "");
                var $btn = $('<button class="btn btn-sm btn-default">Save</button>').on("click", function () {
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
        loadInto("#ovhvps_dns_panel", "dns_list", asJson);
    }

    $(document).on("submit", "#ovhvps_dns_add", function (e) {
        e.preventDefault();
        var domain = $(this).find('[name="domain"]').val();
        var ip = $(this).find('[name="ip"]').val();
        run("dns_add", { domain: domain, ip: ip }).done(loadNetwork);
    });

    // --- upgrade ---
    function loadUpgrade() {
        loadInto("#ovhvps_upgrade_panel", "upgrade_list", asJson);
    }

    // --- graphs ---
    function loadGraphs() {
        loadInto("#ovhvps_graphs_panel", "graphs", asJson);
    }

    // --- n8n access info (shown when the installed OS is an n8n image) ---
    function loadN8n() {
        call("n8n_status").done(function (res) {
            if (res && res.status === "OK" && res.data) {
                var d = res.data;
                var link = d.url
                    ? '<a href="' + d.url + '" target="_blank" rel="noopener">' + d.url + "</a>"
                    : "(provisioning)";
                $("#ovhvps_n8n_panel").html(
                    "<table class='table'>" +
                    "<tr><td><b>Server IP</b></td><td>" + (d.ip || "") + "</td></tr>" +
                    "<tr><td><b>n8n editor</b></td><td>" + link + "</td></tr>" +
                    "<tr><td><b>State</b></td><td>" + (d.state || "unknown") + "</td></tr>" +
                    "</table>" +
                    "<p class='text-muted'>Default n8n port is 5678. If you enabled HTTPS/a reverse proxy on the image, use that address instead.</p>"
                );
            } else {
                $("#ovhvps_n8n_panel").text((res && res.message) || "n8n info unavailable.");
            }
        });
    }
})(jQuery);
