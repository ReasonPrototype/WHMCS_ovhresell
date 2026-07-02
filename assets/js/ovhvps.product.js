/* OVH VPS product-config panel: generates configurable options for the product.
   The panel HTML is emitted at the page footer by the AdminAreaFooterOutput hook;
   this script relocates it INTO the "Module Settings" tab (right after the
   module's config fields) and wires the button. If the Module Settings layout
   isn't found, the panel stays at the footer (no regression). */
(function ($) {
    "use strict";

    var $root = $("#ovhvps_product");
    if (!$root.length) {
        return;
    }
    var cfg = {
        pid: $root.data("pid"),
        csrf: $root.data("csrf"),
        ajaxUrl: $root.data("ajax"),
        hasOptions: String($root.data("has-options")) === "1"
    };

    // --- Relocate the panel into the Module Settings tab -------------------
    // WHMCS renders the module's config fields as inputs named
    // packageconfigoption[N]. We drop the panel right after the last one. The
    // Module Settings tab can be AJAX-loaded, so if the fields aren't present
    // yet we watch the DOM and relocate when they appear.
    function relocate() {
        if ($root.data("ovhvpsMoved")) {
            return true;
        }
        var $fields = $('[name^="packageconfigoption"]').filter(":input");
        if (!$fields.length) {
            return false;
        }
        var $last = $fields.last();
        var $row = $last.closest("tr");
        if ($row.length) {
            var cols = $row.children("td,th").length || 2;
            $("<tr>")
                .append($("<td>").attr("colspan", cols).append($root))
                .insertAfter($row);
        } else {
            var $container = $last.closest(".form-group, fieldset, .row");
            if ($container.length) {
                $root.insertAfter($container);
            } else {
                $last.closest("form").append($root);
            }
        }
        $root.css({ margin: "12px 0" });
        $root.data("ovhvpsMoved", true);
        return true;
    }

    if (!relocate() && window.MutationObserver) {
        var observer = new MutationObserver(function () {
            if (relocate()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        // Give up after 15s; the panel simply stays at the footer.
        window.setTimeout(function () { observer.disconnect(); }, 15000);
    }

    // --- Status + button (delegated so it survives the relocation) ---------
    function status(kind, msg) {
        var colors = { ok: "#0f5132", err: "#842029", work: "#1c4587" };
        $("#ovhvps_product_status").css("color", colors[kind] || "#333").text(msg);
    }

    $(document).on("click", "#ovhvps_gen_btn", function (e) {
        e.preventDefault();
        if (cfg.hasOptions && !window.confirm("Options already exist for this product. This syncs them with the OVH catalog: existing options keep their prices, new ones start at price 0, and options no longer offered are removed. Continue?")) {
            return;
        }
        status("work", "Syncing catalog and generating options…");
        $.ajax({
            url: cfg.ajaxUrl, method: "POST", dataType: "json",
            data: { action: "admin_setup_options", pid: cfg.pid, csrf: cfg.csrf }
        })
            .done(function (res) {
                if (!res || res.status === "Error") {
                    status("err", (res && res.message) || "Failed.");
                    return;
                }
                var d = res.data || {};
                status("ok", (res.message || "Done.") + " (OS: " + (d.os || 0) +
                    ", Datacenters: " + (d.datacenters || 0) + ", Extras: " + (d.options || 0) +
                    "). Refresh the page and set prices on any NEW extras (existing prices were kept).");
                cfg.hasOptions = true;
            })
            .fail(function () { status("err", "Network error."); });
    });
})(jQuery);
