{literal}
<style>
#mcph { --line:#e3e8ee; --ink:#1f2937; --muted:#7b8794; --soft:#f7f9fb;
    font-size:13px; color:var(--ink); background:#fff; padding:0 0 24px; }
#mcph * { box-sizing:border-box; }
#mcph .h-link { color:#2563eb; cursor:pointer; text-decoration:none; }
#mcph .h-link:hover { text-decoration:underline; }
#mcph .h-bar { display:flex; align-items:flex-end; gap:12px; padding:10px 14px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
#mcph .h-bar h2 { margin:0 8px 4px 0; font-size:16px; font-weight:600; }
#mcph .h-field { display:flex; flex-direction:column; gap:2px; }
#mcph .h-flabel { color:var(--muted); font-size:11px; }
#mcph input, #mcph select { padding:5px 8px; border:1px solid var(--line); border-radius:5px; font-size:13px; max-width:170px; }
#mcph .h-stat { color:var(--muted); margin-left:auto; }
#mcph button.h-btn { padding:6px 12px; border:1px solid var(--line); background:#fff; border-radius:5px; cursor:pointer; font-size:13px; color:var(--ink); }
#mcph button.h-btn:hover { background:var(--soft); }
#mcph button.h-btn[disabled] { opacity:.45; cursor:default; }
#mcph button.h-primary { background:#eef4ff; border-color:#b9d0fb; color:#1d4ed8; }
#mcph button.h-danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
#mcph .h-right { margin-left:auto; }

#mcph .h-body { display:flex; align-items:flex-start; }
#mcph .h-main { flex:1 1 auto; min-width:0; }
#mcph .h-side { width:290px; flex:0 0 290px; border-left:1px solid var(--line); padding:12px 14px; background:var(--soft); }
#mcph .h-side h3 { margin:0 0 8px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
#mcph .h-cp { padding:8px 0; border-bottom:1px solid var(--line); }
#mcph .h-cp button { margin-top:6px; }

#mcph .h-table { width:100%; border-collapse:collapse; }
#mcph .h-table th { text-align:left; color:var(--muted); font-weight:500; font-size:11px; padding:7px 8px; border-bottom:1px solid var(--line); }
#mcph .h-table td { padding:7px 8px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcph .h-table tbody tr { cursor:pointer; }
#mcph .h-table tbody tr:hover { background:var(--soft); }
#mcph .h-table tbody tr.sel { background:#eef4ff; }
#mcph .h-num { color:var(--muted); font-variant-numeric:tabular-nums; }
#mcph .h-nowrap { white-space:nowrap; }
#mcph .h-summary { word-break:break-word; }
#mcph .h-objects { min-width:150px; }
#mcph .h-kind { display:inline-block; font-size:11px; color:#475569; background:#eef2f7; border-radius:3px; padding:0 5px; }
#mcph .h-muted { color:var(--muted); }
#mcph .h-pad { padding:14px; }
#mcph .h-src { font-size:11px; padding:1px 6px; border-radius:9px; background:#eef2f7; }
#mcph .h-src-mcp { background:#e0ecff; color:#1d4ed8; }
#mcph .h-src-manager { background:#e3f6e8; color:#166534; }
#mcph .h-src-external { background:#fff3d6; color:#92400e; }
#mcph .h-undone { color:#92400e; }
#mcph .h-pager { display:flex; align-items:center; gap:10px; padding:10px 14px; }

#mcph .h-detail { padding:0 14px; }
#mcph .h-detail:not(:empty) { border-top:2px solid var(--line); padding-top:12px; }
#mcph .h-dhead { display:flex; align-items:center; }
#mcph .h-dhead h3 { margin:0; font-size:15px; }
#mcph .h-obj { border:1px solid var(--line); border-radius:6px; margin:10px 0; padding:8px 10px; }
#mcph .h-objhead { margin-bottom:6px; }
#mcph .h-op { font-size:11px; padding:1px 6px; border-radius:3px; background:#eef2f7; }
#mcph .h-op-create { background:#e3f6e8; color:#166534; }
#mcph .h-op-delete { background:#fee2e2; color:#b91c1c; }
#mcph .h-field-row { margin:6px 0; }
#mcph .h-fname { font-weight:600; font-size:12px; }
#mcph .h-field-row pre, #mcph .h-diff { margin:2px 0; padding:4px 6px; background:var(--soft); border-radius:4px; white-space:pre-wrap; word-break:break-word; font-size:12px; }
#mcph .h-old pre { background:#fef2f2; }
#mcph .h-new pre { background:#f0fdf4; }
#mcph .h-tag { font-size:10px; color:var(--muted); text-transform:uppercase; }
#mcph .h-diff { padding:0; }
#mcph .h-diff div { padding:0 6px; }
#mcph .h-del { background:#fee2e2; color:#991b1b; }
#mcph .h-ins { background:#dcfce7; color:#166534; }
#mcph .h-hunk { background:#e8eef6; color:#475569; }
#mcph .h-note, #mcph .h-warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:7px 9px; border-radius:5px; margin:8px 0; }
#mcph .h-warn { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
#mcph .h-error { color:#b91c1c; }
#mcph .h-ok { color:#166534; margin:8px 0; }
#mcph .h-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:10px 0; }
#mcph .h-plan h4 { margin:10px 0 4px; font-size:13px; }
#mcph .h-planlist { margin:6px 0; padding-left:18px; }
#mcph .h-conflict { color:#b91c1c; }
#mcph .h-reason { font-size:12px; background:#fef2f2; padding:2px 6px; border-radius:4px; margin-top:2px; }
</style>
{/literal}
<div id="mcph"></div>
