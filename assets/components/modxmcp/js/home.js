/**
 * modxMCP - the component's main page (Components > modxMCP): copying the endpoint and saving the capability toggles.
 * The rest of the page is rendered on the server. Plain ES5 and MODx.Ajax; texts come from the lexicon (Modxmcp.l),
 * values from the site are put into the page with textContent only.
 */
Ext.onReady(function () {
    if (typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    var cfg = (typeof Modxmcp !== 'undefined') ? Modxmcp : {};
    var L = cfg.l || {};

    // ---- copy the endpoint --------------------------------------------------------------
    var copyBtn = document.getElementById('modxmcp-copy-endpoint');
    var endpoint = document.getElementById('modxmcp-endpoint');
    var copyStatus = document.getElementById('modxmcp-copy-status');
    function selectText(node) {
        var range = document.createRange();
        range.selectNodeContents(node);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    if (copyBtn && endpoint) {
        copyBtn.addEventListener('click', function () {
            var text = endpoint.textContent;
            function fallback() {
                // Plain-HTTP sites have no clipboard API: select the text so that Ctrl+C works.
                selectText(endpoint);
                var done = false;
                try { done = document.execCommand && document.execCommand('copy'); } catch (e) { done = false; }
                if (copyStatus) { copyStatus.textContent = done ? (L.copied || '') : (L.copy_failed || ''); }
            }
            if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    if (copyStatus) { copyStatus.textContent = L.copied || ''; }
                }, fallback);
            } else {
                fallback();
            }
        });
    }

    // ---- capability toggles -------------------------------------------------------------
    var saveBtn = document.getElementById('modxmcp-savegroups');
    if (saveBtn) {
        var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.modxmcp-grp')); };
        var stateKey = function () { return boxes().map(function (b) { return (b.checked ? '1' : '0') + b.getAttribute('data-group'); }).join('|'); };
        var savedState = stateKey();
        var status = document.getElementById('modxmcp-groups-status');

        // Save button is active only while there are unsaved changes.
        function refreshDirty() {
            var dirty = stateKey() !== savedState;
            saveBtn.disabled = !dirty;
            if (status && !dirty) { status.textContent = ''; }
        }
        boxes().forEach(function (b) { b.addEventListener('change', refreshDirty); });

        saveBtn.addEventListener('click', function () {
            var disabled = boxes().filter(function (b) { return !b.checked; }).map(function (b) { return b.getAttribute('data-group'); });
            saveBtn.disabled = true;
            if (status) { status.textContent = L.features_saving || ''; }
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: { action: 'mgr/savegroups', disabled: disabled.join(',') },
                listeners: {
                    success: {
                        fn: function () {
                            savedState = stateKey();
                            saveBtn.disabled = true;
                            if (status) { status.textContent = L.features_saved || ''; }
                        }
                    },
                    failure: {
                        fn: function () {
                            saveBtn.disabled = false;
                            if (status) { status.textContent = L.features_failed || ''; }
                        }
                    }
                }
            });
        });
    }
});
