/* OVH VPS admin service panel. Posts admin_* actions to ajax.php. */
(function ($) {
    "use strict";

    var $root = $("#ovhvps_admin");
    if (!$root.length) {
        return;
    }
    var cfg = {
        serviceid: $root.data("serviceid"),
        csrf: $root.data("csrf"),
        ajaxUrl: $root.data("ajax")
    };

    function status(kind, msg) {
        var colors = { ok: "#0f5132", err: "#842029", work: "#1c4587" };
        $("#ovhvps_admin_status").css("color", colors[kind] || "#333").text(msg);
    }

    $root.on("click", "[data-admin]", function (e) {
        e.preventDefault();
        var $btn = $(this);
        var action = $btn.data("admin");
        var data = { action: action, service_id: cfg.serviceid, csrf: cfg.csrf };

        if ($btn.data("field")) {
            data[$btn.data("name")] = $("#" + $btn.data("field")).val();
        }
        if (typeof $btn.data("enable") !== "undefined") {
            data.enable = $btn.data("enable");
        }
        if (action === "admin_retry_provision" || action === "admin_confirm_termination") {
            if (!window.confirm("Are you sure?")) { return; }
        }

        status("work", "Working…");
        $.ajax({ url: cfg.ajaxUrl, method: "POST", dataType: "json", data: data })
            .done(function (res) {
                if (!res || res.status === "Error") {
                    status("err", (res && res.message) || "Failed.");
                    return;
                }
                var extra = res.data ? " " + JSON.stringify(res.data) : "";
                status("ok", (res.message || "Done.") + extra);
            })
            .fail(function () { status("err", "Network error."); });
    });
})(jQuery);
