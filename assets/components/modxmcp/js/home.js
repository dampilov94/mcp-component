Ext.onReady(function () {
    var btn = document.getElementById('modxmcp-regenerate');
    if (!btn || typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    var cfg = (typeof Modxmcp !== 'undefined') ? Modxmcp : {};
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
});
