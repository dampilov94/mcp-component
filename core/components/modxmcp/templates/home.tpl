<div class="container" style="padding:18px;max-width:840px;">
    <h2 style="margin-top:0;">modxMCP</h2>
    <p style="color:#666;">MCP endpoint for this MODX site. Pair it with the modx-mcp client (set <code>MODX_MCP_SITE_URL</code> + <code>MODX_MCP_TOKEN</code>).</p>

    <table class="modxmcp-status" cellpadding="6" style="border-collapse:collapse;width:100%;margin:14px 0;">
        <tr><td style="width:220px;color:#888;">Endpoint</td><td><code>{$endpoint}</code></td></tr>
        <tr><td style="color:#888;">Enabled</td><td>{if $enabled}<span style="color:#2e7d32;font-weight:bold;">Yes</span>{else}<span style="color:#c62828;font-weight:bold;">No</span> &mdash; set <code>modxmcp.enabled</code> = Yes to activate{/if}</td></tr>
        <tr><td style="color:#888;">API token</td><td>{if $token_set}set (<code>{$token_preview}</code>){else}<span style="color:#c62828;">not set</span> &mdash; click Regenerate below{/if}</td></tr>
        <tr><td style="color:#888;">Auto-static (<code>modxmcp.auto_static</code>)</td><td>{if $auto_static}On{else}Off{/if}</td></tr>
        <tr><td style="color:#888;">Audit log (<code>modxmcp.audit_log</code>)</td><td>{if $audit_log}On{else}Off{/if}</td></tr>
        <tr><td style="color:#888;">Package install (<code>modxmcp.allow_package_install</code>)</td><td>{if $package_install}On{else}Off{/if}</td></tr>
    </table>

    {if $integrations}
    <h3 style="margin:18px 0 6px;">Integrations</h3>
    <p style="color:#666;margin-top:0;">Popular add-ons modxMCP can work with on this site.</p>
    <table class="modxmcp-integrations" cellpadding="6" style="border-collapse:collapse;width:100%;margin:6px 0 14px;">
        {foreach $integrations as $i}
        <tr>
            <td style="width:160px;font-weight:bold;">{$i.label}</td>
            <td style="width:90px;">{if $i.installed}<span style="color:#2e7d32;">✓ {if $i.version}{$i.version}{else}yes{/if}</span>{else}<span style="color:#bbb;">—</span>{/if}</td>
            <td style="color:#666;">{$i.note}</td>
        </tr>
        {/foreach}
    </table>
    {/if}

    <p>
        <button id="modxmcp-regenerate" class="x-btn" style="padding:8px 16px;cursor:pointer;">Regenerate API token</button>
    </p>
    <div id="modxmcp-token-result" style="display:none;margin-top:12px;padding:12px;border:1px solid #cfe3cf;background:#f3faf3;border-radius:4px;">
        <strong>New token (copy it now — shown once):</strong>
        <pre id="modxmcp-token-value" style="white-space:pre-wrap;word-break:break-all;margin:6px 0 0;"></pre>
    </div>

    <p style="color:#999;font-size:12px;margin-top:18px;">Edit detailed behaviour under System &rarr; System Settings, namespace <code>modxmcp</code>.</p>
</div>
