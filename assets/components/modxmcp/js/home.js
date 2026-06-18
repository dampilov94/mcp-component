Ext.onReady(function () {
    var btn = document.getElementById('modxmcp-regenerate');
    if (!btn || typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    btn.addEventListener('click', function () {
        if (!confirm('Regenerate the modxMCP API token?\nExisting MCP clients will stop working until you update MODX_MCP_TOKEN.')) {
            return;
        }
        btn.disabled = true;
        MODx.Ajax.request({
            url: Modxmcp.connector_url,
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
                        alert('Failed to regenerate the token. Check the manager error log.');
                    }
                }
            }
        });
    });
});
