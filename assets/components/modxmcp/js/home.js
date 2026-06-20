Ext.onReady(function () {
    if (typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    var cfg = (typeof Modxmcp !== 'undefined') ? Modxmcp : {};

    var btn = document.getElementById('modxmcp-regenerate');
    if (btn) {
        btn.addEventListener('click', function () {
            var msg = cfg.confirm_regenerate || 'Regenerate the modxMCP API token?';
            if (!confirm(msg)) {
                return;
            }
            btn.disabled = true;
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: { action: 'mgr/regeneratetoken' },
                listeners: {
                    success: {
                        fn: function (r) {
                            btn.disabled = false;
                            var token = (r && r.object && r.object.token) ? r.object.token : '';
                            if (token) {
                                document.getElementById('modxmcp-token-value').textContent = token;
                                document.getElementById('modxmcp-token-result').style.display = 'block';
                            }
                        }
                    },
                    failure: {
                        fn: function () {
                            btn.disabled = false;
                            alert(cfg.regenerate_failed || 'Failed to regenerate the token.');
                        }
                    }
                }
            });
        });
    }

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
            if (status) { status.textContent = 'Сохраняю…'; }
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: { action: 'mgr/savegroups', disabled: disabled.join(',') },
                listeners: {
                    success: {
                        fn: function () {
                            savedState = stateKey();
                            saveBtn.disabled = true;
                            if (status) { status.textContent = 'Сохранено. Применится автоматически при следующем действии ИИ.'; }
                        }
                    },
                    failure: {
                        fn: function () {
                            saveBtn.disabled = false;
                            if (status) { status.textContent = 'Ошибка сохранения. См. журнал ошибок менеджера.'; }
                        }
                    }
                }
            });
        });
    }
});
