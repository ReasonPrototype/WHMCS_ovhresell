/*
 * Cart stock helper for the ovhvps module.
 *  - Disables any <select> option carrying the out-of-stock marker.
 *  - Makes the "Operating System" dropdown react to the chosen "Datacenter"
 *    (and vice versa) using the per-OS matrix embedded by the server in
 *    <script id="ovhvps-stock">. Windows-vs-Linux uses the same rule as the
 *    server: the word "windows" in the option text.
 * Theme/language independent. All blocking is also enforced server-side at
 * checkout, so this is purely a UX aid.
 */
(function () {
    "use strict";

    var MARKER = "Fora de Stock";

    function readData() {
        var el = document.getElementById("ovhvps-stock");
        if (!el) { return null; }
        try { return JSON.parse(el.textContent || "{}"); } catch (e) { return null; }
    }

    function stripMarker(text) {
        var i = text.indexOf(" - " + MARKER);
        return (i !== -1 ? text.slice(0, i) : text).trim();
    }

    function osIsWindows(text) {
        return text.toLowerCase().indexOf("windows") !== -1;
    }

    function markOos() {
        var options = document.getElementsByTagName("option");
        for (var i = 0; i < options.length; i++) {
            if (options[i].text && options[i].text.indexOf(MARKER) !== -1) {
                options[i].disabled = true;
            }
        }
    }

    function findSelectByLabel(heading) {
        var selects = document.getElementsByTagName("select");
        for (var i = 0; i < selects.length; i++) {
            var s = selects[i];
            var scope = s.closest ? s.closest(".form-group, tr, .row, li, div") : null;
            var text = scope ? (scope.textContent || "") : "";
            if (text.indexOf(heading) !== -1) { return s; }
        }
        return null;
    }

    // Pick the product whose datacenter value-map covers this dropdown's values.
    function pickProduct(data, dcSelect) {
        if (!data || !data.matrix) { return null; }
        var pids = Object.keys(data.matrix);
        if (pids.length === 1) {
            return { matrix: data.matrix[pids[0]], dc: (data.dc || {})[pids[0]] || {} };
        }
        for (var p = 0; p < pids.length; p++) {
            var dcMap = (data.dc || {})[pids[p]] || {};
            for (var i = 0; i < dcSelect.options.length; i++) {
                if (dcMap[stripMarker(dcSelect.options[i].text)]) {
                    return { matrix: data.matrix[pids[p]], dc: dcMap };
                }
            }
        }
        return null;
    }

    function selectedText(sel) {
        var o = sel.options[sel.selectedIndex];
        return o ? o.text : "";
    }

    function apply(data) {
        var dcSelect = findSelectByLabel("Datacenter");
        var osSelect = findSelectByLabel("Operating System");
        if (!dcSelect || !osSelect) { return; }
        var picked = pickProduct(data, dcSelect);
        if (!picked) { return; }
        var matrix = picked.matrix, dcMap = picked.dc;

        // Forward: chosen datacenter -> disable OS options it cannot serve.
        var code = dcMap[stripMarker(selectedText(dcSelect))];
        var avail = code ? matrix[code] : null;
        for (var i = 0; i < osSelect.options.length; i++) {
            var opt = osSelect.options[i];
            if (opt.text.indexOf(MARKER) !== -1) { continue; }
            opt.disabled = avail ? (osIsWindows(opt.text) ? !avail.windows : !avail.linux) : false;
        }
        // If the selected OS is now disabled, jump to the first enabled one.
        if (osSelect.options[osSelect.selectedIndex] && osSelect.options[osSelect.selectedIndex].disabled) {
            for (var j = 0; j < osSelect.options.length; j++) {
                if (!osSelect.options[j].disabled) { osSelect.selectedIndex = j; break; }
            }
        }

        // Reverse: chosen OS -> disable datacenters that lack that OS class.
        var wantWindows = osIsWindows(selectedText(osSelect));
        for (var k = 0; k < dcSelect.options.length; k++) {
            var dopt = dcSelect.options[k];
            if (dopt.text.indexOf(MARKER) !== -1) { continue; }
            var dcode = dcMap[stripMarker(dopt.text)];
            var a = dcode ? matrix[dcode] : null;
            dopt.disabled = a ? (wantWindows ? !a.windows : !a.linux) : false;
        }
    }

    function run(data) {
        markOos();
        if (data) { apply(data); }
    }

    function init() {
        var data = readData();
        run(data);
        document.addEventListener("change", function () { run(data); }, true);
        setTimeout(function () { run(data); }, 800);
        setTimeout(function () { run(data); }, 2000);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
