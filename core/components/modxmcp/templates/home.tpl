<div class="container" style="padding:18px;max-width:840px;">
    <h2 style="margin-top:0;">modxMCP</h2>
    <p style="color:#666;">{$l.intro}</p>

    <table class="modxmcp-status" cellpadding="6" style="border-collapse:collapse;width:100%;margin:14px 0;">
        <tr><td style="width:240px;color:#888;">{$l.endpoint}</td><td><code>{$endpoint}</code></td></tr>
        <tr><td style="color:#888;">{$l.enabled}</td><td>{if $enabled}<span style="color:#2e7d32;font-weight:bold;">{$l.yes}</span>{else}<span style="color:#c62828;font-weight:bold;">{$l.no}</span> &mdash; {$l.enable_hint}{/if}</td></tr>
        <tr><td style="color:#888;">{$l.token}</td><td>{if $token_set}{$l.token_set} (<code>{$token_preview}</code>){else}<span style="color:#c62828;">{$l.token_notset}</span>{/if}</td></tr>
        <tr><td style="color:#888;">{$l.auto_static} (<code>modxmcp.auto_static</code>)</td><td>{if $auto_static}{$l.on}{else}{$l.off}{/if}</td></tr>
        <tr><td style="color:#888;">{$l.audit_log} (<code>modxmcp.audit_log</code>)</td><td>{if $audit_log}{$l.on}{else}{$l.off}{/if}</td></tr>
        <tr><td style="color:#888;">{$l.package_install} (<code>modxmcp.allow_package_install</code>)</td><td>{if $package_install}{$l.on}{else}{$l.off}{/if}</td></tr>
    </table>

    <p>
        <button id="modxmcp-regenerate" class="x-btn" style="padding:8px 16px;cursor:pointer;">{$l.regenerate}</button>
    </p>
    <div id="modxmcp-token-result" style="display:none;margin-top:12px;padding:12px;border:1px solid #cfe3cf;background:#f3faf3;border-radius:4px;">
        <strong>{$l.new_token}</strong>
        <pre id="modxmcp-token-value" style="white-space:pre-wrap;word-break:break-all;margin:6px 0 0;"></pre>
    </div>

    {if $integrations}
    <h3 style="margin:18px 0 6px;">{$l.integrations}</h3>
    <p style="color:#666;margin-top:0;">{$l.integrations_intro}</p>
    <table class="modxmcp-integrations" cellpadding="6" style="border-collapse:collapse;width:100%;margin:6px 0 14px;">
        {foreach $integrations as $i}
        <tr>
            <td style="width:160px;font-weight:bold;">{$i.label}</td>
            <td style="width:90px;">{if $i.installed}<span style="color:#2e7d32;">✓ {if $i.version}{$i.version}{else}{$l.yes}{/if}</span>{else}<span style="color:#bbb;">—</span>{/if}</td>
            <td style="color:#666;">{$i.note}</td>
        </tr>
        {/foreach}
    </table>
    {/if}

    <p style="color:#999;font-size:12px;margin-top:18px;">{$l.settings_hint}</p>
</div>
