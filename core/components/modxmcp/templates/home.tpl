{literal}
<style>
#mcpo { --line:#e3e8ee; --ink:#1f2937; --muted:#7b8794; --soft:#f7f9fb; --ok:#2e7d32; --bad:#c62828;
    max-width:900px; padding:18px; font-size:13px; color:var(--ink); }
#mcpo * { box-sizing:border-box; }
#mcpo h2 { margin:0 0 4px; font-size:20px; }
#mcpo h3 { margin:22px 0 6px; font-size:15px; }
#mcpo h4 { margin:12px 0 4px; color:#555; }
#mcpo .o-muted { color:var(--muted); }
#mcpo .o-lead { color:#666; margin:0 0 6px; }
#mcpo code { background:var(--soft); padding:1px 4px; border-radius:3px; }
#mcpo .o-status { width:100%; border-collapse:collapse; margin:10px 0; }
#mcpo .o-status td { padding:6px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcpo .o-status td:first-child { width:200px; color:var(--muted); }
#mcpo .o-ok { color:var(--ok); font-weight:bold; }
#mcpo .o-bad { color:var(--bad); font-weight:bold; }
#mcpo .o-link { color:#2563eb; text-decoration:none; }
#mcpo .o-link:hover { text-decoration:underline; }
#mcpo .o-btn { display:inline-block; padding:5px 12px; border:1px solid var(--line); background:#fff; border-radius:5px; cursor:pointer; font-size:12px; color:var(--ink); text-decoration:none; }
#mcpo .o-btn:hover { background:var(--soft); }
#mcpo .o-recent { width:100%; border-collapse:collapse; margin:6px 0; }
#mcpo .o-recent td { padding:5px 6px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcpo .o-src { font-size:11px; padding:1px 6px; border-radius:9px; background:#eef2f7; white-space:nowrap; }
#mcpo .o-src-mcp { background:#e0ecff; color:#1d4ed8; }
#mcpo .o-src-manager { background:#e3f6e8; color:#166534; }
#mcpo .o-src-external { background:#fff3d6; color:#92400e; }
#mcpo .o-counts { margin:6px 0; color:#555; }
#mcpo .o-counts span { margin-right:14px; }
#mcpo .o-tiles { display:flex; flex-wrap:wrap; gap:12px; margin:16px 0; }
#mcpo .o-tile { flex:1 1 240px; padding:14px 16px; border:1px solid var(--line); border-radius:6px; background:#fbfcfd; }
#mcpo .o-tile b { display:block; margin-bottom:3px; font-size:14px; }
#mcpo .o-tile p { margin:0 0 10px; color:#666; }
#mcpo .o-warn { color:var(--bad); }
#mcpo .o-danger { width:100%; border-collapse:collapse; margin:6px 0; }
#mcpo .o-danger td { padding:6px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcpo .o-danger td:first-child { width:240px; }
#mcpo .o-state-on { font-weight:bold; }
#mcpo .o-state-off { color:#555; }
#mcpo .o-state-missing { color:var(--muted); }
#mcpo .o-integrations { width:100%; border-collapse:collapse; margin:6px 0; }
#mcpo .o-integrations td { padding:6px; }
#mcpo .d-intro { margin:0 0 6px; color:#666; }
#mcpo .d-table { width:100%; border-collapse:collapse; margin:6px 0; }
#mcpo .d-table th { text-align:left; font-weight:normal; color:var(--muted); padding:4px 6px; border-bottom:1px solid var(--line); }
#mcpo .d-table td { padding:6px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcpo .d-actions button, #mcpo .d-actions a { margin:0 6px 4px 0; }
#mcpo .d-plan { margin:6px 0 10px; padding:10px 12px; border:1px solid var(--line); border-radius:5px; background:#fbfcfd; }
#mcpo .d-plan h4 { margin:0 0 6px; }
#mcpo .d-change { margin:6px 0; }
#mcpo .d-change b { font-weight:bold; }
#mcpo .d-old { color:#92400e; white-space:pre-wrap; }
#mcpo .d-new { color:#166534; white-space:pre-wrap; }
#mcpo .d-diff { margin:4px 0; padding:6px 8px; background:#fff; border:1px solid var(--line); border-radius:3px; white-space:pre-wrap; font-family:monospace; font-size:12px; max-height:260px; overflow:auto; }
#mcpo .d-warn { margin:6px 0; padding:6px 8px; background:#fff3d6; color:#92400e; border-radius:4px; }
#mcpo .d-error { margin:6px 0; color:var(--bad); }
#mcpo .d-ok { margin:6px 0; color:var(--ok); }
</style>
{/literal}
<div id="mcpo">
    <h2>modxMCP</h2>
    <p class="o-lead">{$l.intro}</p>

    <h3>{$l.heading_status}</h3>
    <table class="o-status">
        <tr><td>{$l.enabled}</td><td>{if $overview.enabled}<span class="o-ok">{$l.yes}</span>{else}<span class="o-bad">{$l.no}</span> &mdash; {$l.enable_hint}{/if}</td></tr>
        <tr><td>{$l.version}</td><td><code>{$overview.version|escape}</code></td></tr>
        <tr><td>{$l.endpoint}</td><td><code id="modxmcp-endpoint">{$overview.endpoint|escape}</code> <button type="button" class="o-btn" id="modxmcp-copy-endpoint">{$l.copy}</button> <span class="o-muted" id="modxmcp-copy-status"></span></td></tr>
        <tr><td>{$l.active_tokens}</td><td>{if $overview.active_tokens > 0}{$l.tokens_count|escape} &mdash; <a class="o-link" href="?a=tokens&amp;namespace=modxmcp">{$l.tokens_link}</a>{else}<span class="o-warn">{$l.no_tokens}</span> &mdash; <a class="o-link" href="?a=tokens&amp;namespace=modxmcp">{$l.tokens_link}</a>{/if}</td></tr>
        <tr><td>{$l.auto_static} (<code>modxmcp.auto_static</code>)</td><td>{if $overview.auto_static}{$l.on}{else}{$l.off}{/if}</td></tr>
    </table>

    <div id="modxmcp-drafts" style="display:none;">
        <h3>{$l.drafts_title}</h3>
        <div id="modxmcp-drafts-body"></div>
    </div>

    <h3>{$l.recent_title}</h3>
    {if $recent}
    <table class="o-recent">
        {foreach $recent as $c}
        <tr>
            <td style="white-space:nowrap;" class="o-muted">{$c.time}</td>
            <td><span class="o-src o-src-{$c.source|escape}">{$c.label|escape}</span></td>
            <td>{$c.author|escape}</td>
            <td>{$c.summary|escape}</td>
        </tr>
        {/foreach}
    </table>
    {else}
    <p class="o-muted">{$l.recent_empty}</p>
    {/if}
    <div class="o-counts"><span class="o-muted">{$l.recent_24h}:</span>{foreach $counts as $c}<span>{$c.label|escape}: <b>{$c.n}</b></span>{/foreach}</div>
    <a class="o-link" href="?a=history&amp;namespace=modxmcp">{$l.recent_all} &rarr;</a>

    <div class="o-tiles">
        <div class="o-tile"><b>{$l.nav_tokens}</b><p>{$l.nav_tokens_desc}</p><a class="o-btn" href="?a=tokens&amp;namespace=modxmcp">{$l.nav_open} &rarr;</a></div>
        <div class="o-tile"><b>{$l.nav_history}</b><p>{$l.nav_history_desc}</p><a class="o-btn" href="?a=history&amp;namespace=modxmcp">{$l.nav_open} &rarr;</a></div>
        <div class="o-tile"><b>{$l.nav_graph}</b><p>{$l.nav_graph_desc}</p><a class="o-btn" href="?a=graph&amp;namespace=modxmcp">{$l.nav_open} &rarr;</a></div>
    </div>

    {if $components || $features}
    <h3>{$l.features_title}</h3>
    <p class="o-lead">{$l.features_intro}</p>

    {if $components}
    <h4>{$l.features_components}</h4>
    <div style="margin:2px 0 8px;">
        {foreach $components as $g}
        <label style="display:block;margin:4px 0;cursor:pointer;">
            <input type="checkbox" class="modxmcp-grp" data-group="{$g.key|escape}"{if $g.enabled} checked{/if}> {$g.label|escape} <span style="color:#bbb;">(<code>{$g.key|escape}</code>)</span>
        </label>
        {/foreach}
    </div>
    {/if}

    {if $features}
    <h4>{$l.features_modx}</h4>
    <div style="margin:2px 0 8px;">
        {foreach $features as $g}
        <label style="display:block;margin:4px 0;cursor:pointer;">
            <input type="checkbox" class="modxmcp-grp" data-group="{$g.key|escape}"{if $g.enabled} checked{/if}> {$g.label|escape} <span style="color:#bbb;">(<code>{$g.key|escape}</code>)</span>
        </label>
        {/foreach}
    </div>
    {/if}

    <p>
        <button id="modxmcp-savegroups" class="x-btn" style="padding:8px 16px;cursor:pointer;" disabled>{$l.features_save}</button>
        <span id="modxmcp-groups-status" style="margin-left:10px;color:#888;"></span>
    </p>
    {/if}

    {if $integrations}
    <h3>{$l.integrations}</h3>
    <p class="o-lead">{$l.integrations_intro}</p>
    <table class="o-integrations">
        {foreach $integrations as $i}
        <tr>
            <td style="width:160px;font-weight:bold;">{$i.label|escape}</td>
            <td style="width:90px;">{if $i.installed}<span style="color:#2e7d32;">✓ {if $i.version}{$i.version|escape}{else}{$l.yes}{/if}</span>{else}<span style="color:#bbb;">—</span>{/if}</td>
            <td style="color:#666;">{if $i.available}{$l.integration_available}{elseif $i.installed}{$l.integration_files_missing}{else}{$l.integration_not_installed}{/if}</td>
        </tr>
        {/foreach}
    </table>
    {/if}

    {if $dangerous}
    <h3>{$l.danger_title}</h3>
    <p class="o-lead">{$l.danger_intro}</p>
    <table class="o-danger">
        {foreach $dangerous as $d}
        <tr>
            <td><code>{$d.key|escape}</code></td>
            <td style="width:150px;"><span class="o-state-{$d.class|escape}">{$d.state|escape}</span></td>
            <td style="color:#666;">{$d.desc|escape}</td>
        </tr>
        {/foreach}
    </table>
    {/if}

    <p style="color:#999;font-size:12px;margin-top:18px;">{$l.settings_hint}</p>
</div>
