{literal}
<style>
/* Height is measured in JS (see fitShell) — hard-coding calc(100vh - <guess>) clipped the
   bottom of the panel whenever the manager chrome was taller than the guess, with no scroll
   to reach it. This is only the pre-measure fallback. */
#mcpg { --line:#e3e8ee; --ink:#1f2937; --muted:#7b8794; --bg:#fff; --soft:#f7f9fb;
    display:flex; flex-direction:column; height:620px; min-height:420px;
    font-size:13px; color:var(--ink); background:var(--bg); }
#mcpg * { box-sizing:border-box; }
#mcpg a { color:#2563eb; text-decoration:none; cursor:pointer; }
#mcpg a:hover { text-decoration:underline; }

/* toolbar */
#mcpg .g-bar { display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid var(--line); flex:0 0 auto; flex-wrap:wrap; }
#mcpg .g-bar h2 { margin:0; font-size:16px; font-weight:600; }
#mcpg .g-search { position:relative; }
#mcpg .g-search input { width:230px; padding:6px 9px; border:1px solid var(--line); border-radius:5px; font-size:13px; }
#mcpg .g-results { position:absolute; z-index:30; top:100%; left:0; width:290px; max-height:280px; overflow:auto;
    background:#fff; border:1px solid var(--line); border-radius:6px; box-shadow:0 6px 18px rgba(16,24,40,.12); display:none; }
#mcpg .g-results div { padding:6px 10px; cursor:pointer; display:flex; align-items:center; gap:7px; }
#mcpg .g-results div:hover, #mcpg .g-results div.sel { background:var(--soft); }
#mcpg .g-stat { color:var(--muted); margin-left:auto; white-space:nowrap; }
#mcpg button.g-btn { padding:6px 11px; border:1px solid var(--line); background:#fff; border-radius:5px; cursor:pointer; font-size:13px; color:var(--ink); }
#mcpg button.g-btn:hover { background:var(--soft); }
#mcpg button.g-btn[disabled] { opacity:.45; cursor:default; }
#mcpg button.g-btn.on { background:#eef4ff; border-color:#b9d0fb; color:#1d4ed8; }
#mcpg .g-seg { display:inline-flex; }
#mcpg .g-seg button { border-radius:0; margin-left:-1px; }
#mcpg .g-seg button:first-child { border-radius:5px 0 0 5px; margin-left:0; }
#mcpg .g-seg button:last-child { border-radius:0 5px 5px 0; }
#mcpg .g-seg button.on { position:relative; z-index:1; }

/* body */
#mcpg .g-body { display:flex; flex:1 1 auto; min-height:0; }
#mcpg .g-side { width:250px; flex:0 0 250px; border-right:1px solid var(--line); overflow:auto; padding:12px; background:var(--soft); }
#mcpg .g-side h3 { margin:0 0 7px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
#mcpg .g-side section { margin-bottom:18px; }
#mcpg .g-side label { display:flex; align-items:center; gap:6px; padding:3px 0; cursor:pointer; }
#mcpg .g-side label span.c { flex:1 1 auto; }
#mcpg .g-side label span.n { color:var(--muted); font-variant-numeric:tabular-nums; }
#mcpg .g-list { list-style:none; margin:0; padding:0; max-height:230px; overflow:auto; }
#mcpg .g-list li { padding:3px 5px; border-radius:4px; cursor:pointer; display:flex; gap:6px; align-items:baseline; }
#mcpg .g-list li:hover { background:#eef2f7; }
#mcpg .g-list li .t { color:var(--muted); font-size:11px; }
#mcpg .g-empty-note { color:var(--muted); margin:0; }

/* canvas */
#mcpg .g-stage { position:relative; flex:1 1 auto; min-width:0; background:
    radial-gradient(circle at 1px 1px, #eef1f5 1px, transparent 0) 0 0/22px 22px, #fff; }
#mcpg canvas { display:block; width:100%; height:100%; cursor:grab; }
#mcpg canvas.drag { cursor:grabbing; }
#mcpg .g-overlay { position:absolute; top:0; left:0; right:0; bottom:0; display:flex; align-items:center;
    justify-content:center; color:var(--muted); pointer-events:none; text-align:center; padding:20px; }
#mcpg .g-crumb { position:absolute; top:10px; left:12px; display:none; align-items:center; gap:8px;
    background:rgba(255,255,255,.95); border:1px solid var(--line); border-radius:20px; padding:5px 8px 5px 12px;
    box-shadow:0 2px 8px rgba(16,24,40,.08); max-width:calc(100% - 24px); }
#mcpg .g-crumb b { font-weight:600; }
#mcpg .g-hint { position:absolute; bottom:10px; left:12px; color:#9aa5b1; font-size:11px; pointer-events:none; }
#mcpg .g-legend { position:absolute; bottom:10px; right:12px; display:flex; gap:10px; flex-wrap:wrap;
    justify-content:flex-end; max-width:60%; pointer-events:none; }
#mcpg .g-legend span { display:flex; align-items:center; gap:4px; color:var(--muted); font-size:11px; }

/* details */
#mcpg .g-info { width:290px; flex:0 0 290px; border-left:1px solid var(--line); overflow:auto; padding:14px; display:none; }
#mcpg .g-info.open { display:block; }
#mcpg .g-info h3 { margin:0 0 2px; font-size:15px; word-break:break-word; }
#mcpg .g-info .meta { color:var(--muted); margin-bottom:10px; }
#mcpg .g-info .acts { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
#mcpg .g-info h4 { margin:12px 0 4px; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
#mcpg .g-info .warn { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:7px 9px; border-radius:5px; margin-bottom:10px; }
#mcpg .g-info .note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:7px 9px; border-radius:5px; margin-bottom:10px; }

#mcpg .dot { display:inline-block; width:9px; height:9px; border-radius:50%; flex:0 0 auto; }
#mcpg .dot.hollow { background:#fff !important; border:2px solid #ef4444; width:7px; height:7px; }
</style>
{/literal}

<div id="mcpg">
    <div class="g-bar">
        <h2>{$graph_labels.heading|escape}</h2>
        <div class="g-search">
            <input type="search" id="g-q" placeholder="{$graph_labels.search|escape}" autocomplete="off">
            <div class="g-results" id="g-res"></div>
        </div>
        <span class="g-seg">
            <button class="g-btn g-lay on" data-lay="lanes" title="{$graph_labels.lanes_hint|escape}">{$graph_labels.lanes|escape}</button><button class="g-btn g-lay" data-lay="clusters" title="{$graph_labels.clusters_hint|escape}">{$graph_labels.clusters|escape}</button><button class="g-btn g-lay" data-lay="free" title="{$graph_labels.free_hint|escape}">{$graph_labels.free|escape}</button>
        </span>
        <button class="g-btn" id="g-reload">{$graph_labels.reload|escape}</button>
        <button class="g-btn" id="g-fit">{$graph_labels.fit|escape}</button>
        <button class="g-btn" id="g-reset" disabled>{$graph_labels.reset|escape}</button>
        <span class="g-stat" id="g-stat">{$graph_labels.loading|escape}</span>
        <a href="{$settings_url}" style="margin-left:4px;">{$graph_labels.settings|escape}&nbsp;&rarr;</a>
    </div>

    <div class="g-body">
        <div class="g-side">
            <section>
                <h3>{$graph_labels.types|escape}</h3>
                <div id="g-types"></div>
            </section>
            <section>
                <h3>{$graph_labels.filters|escape}</h3>
                <label title="{$graph_labels.vendor_hint|escape}"><input type="checkbox" id="g-hide-vendor"><span class="c">{$graph_labels.hide_vendor|escape}</span><span class="n" id="g-nvendor">0</span></label>
                <label><input type="checkbox" id="g-hide-orphans"><span class="c">{$graph_labels.hide_orphans|escape}</span><span class="n" id="g-norph2">0</span></label>
                <label><input type="checkbox" id="g-only-issues"><span class="c">{$graph_labels.issues_only|escape}</span></label>
                <label><input type="checkbox" id="g-res-nodes"><span class="c">{$graph_labels.resource_links|escape}<code>[[~id]]</code></span></label>
            </section>
            <section>
                <h3>{$graph_labels.density|escape}</h3>
                <input type="range" id="g-spread" min="60" max="260" value="100" style="width:100%">
                <div style="display:flex;justify-content:space-between;color:#7b8794;font-size:11px;">
                    <span>{$graph_labels.dense|escape}</span><span>{$graph_labels.spread|escape}</span>
                </div>
            </section>
            <section>
                <h3>{$graph_labels.broken_links|escape} (<span id="g-nmiss">0</span>)</h3>
                <ul class="g-list" id="g-miss"></ul>
            </section>
            <section>
                <h3>{$graph_labels.unused|escape} (<span id="g-norph">0</span>)</h3>
                <ul class="g-list" id="g-orph"></ul>
                <p class="g-empty-note" id="g-orph-note" style="font-size:11px;margin-top:6px;"></p>
            </section>
        </div>

        <div class="g-stage">
            <canvas id="g-canvas"></canvas>
            <div class="g-crumb" id="g-crumb"></div>
            <div class="g-overlay" id="g-overlay">{$graph_labels.loading_graph|escape}</div>
            <div class="g-hint">{$graph_labels.controls|escape}</div>
            <div class="g-legend" id="g-legend"></div>
        </div>

        <div class="g-info" id="g-info"></div>
    </div>
</div>
