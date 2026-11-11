Ext.onReady(function () {
    if (typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    var cfg = (typeof Modxmcp !== 'undefined') ? Modxmcp : {};

    var createBtn = document.getElementById('modxmcp-create-token');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            createBtn.disabled = true;
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: {
                    action: 'mgr/createtoken',
                    label: document.getElementById('modxmcp-token-label').value,
                    scope: document.getElementById('modxmcp-token-scope').value,
                    plan_only: document.getElementById('modxmcp-token-plan').checked ? '1' : '0'
                },
                listeners: {
                    success: {
                        fn: function (r) {
                            createBtn.disabled = false;
                            var token = (r && r.object && r.object.token) ? r.object.token : '';
                            if (token) {
                                // The table is refreshed on the next page load; the token itself is shown only now.
                                document.getElementById('modxmcp-token-value').textContent = token;
                                document.getElementById('modxmcp-token-result').style.display = 'block';
                            }
                        }
                    },
                    failure: {
                        fn: function () {
                            createBtn.disabled = false;
                            alert('Не удалось создать токен. См. журнал ошибок менеджера.');
                        }
                    }
                }
            });
        });
    }

    Array.prototype.slice.call(document.querySelectorAll('.modxmcp-revoke')).forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('Отозвать токен? Клиенты с этим токеном сразу перестанут работать. Отменить отзыв нельзя.')) {
                return;
            }
            button.disabled = true;
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: { action: 'mgr/revoketoken', id: button.getAttribute('data-id') },
                listeners: {
                    success: { fn: function () { window.location.reload(); } },
                    failure: {
                        fn: function () {
                            button.disabled = false;
                            alert('Не удалось отозвать токен. См. журнал ошибок менеджера.');
                        }
                    }
                }
            });
        });
    });

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
