/*
 * Disables any <select> option whose text carries the out-of-stock marker
 * ("Fora de Stock"), which the module appends server-side to out-of-stock
 * datacenters. Theme- and language-independent: it keys off the marker text,
 * not WHMCS internals. The option stays visible (greyed by the browser) but
 * cannot be selected.
 */
(function () {
    "use strict";

    var MARKER = "Fora de Stock";

    function mark() {
        var options = document.getElementsByTagName("option");
        for (var i = 0; i < options.length; i++) {
            if (options[i].text && options[i].text.indexOf(MARKER) !== -1) {
                options[i].disabled = true;
            }
        }
    }

    function init() {
        mark();
        // Re-run for order forms whose config step renders after load / via AJAX.
        setTimeout(mark, 800);
        setTimeout(mark, 2000);
        document.addEventListener("change", mark, true);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
