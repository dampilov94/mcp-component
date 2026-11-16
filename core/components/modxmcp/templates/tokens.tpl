{literal}
<style>
#mcpt { --line:#e3e8ee; --ink:#1f2937; --muted:#7b8794; --soft:#f7f9fb;
    font-size:13px; color:var(--ink); background:#fff; padding:0 0 24px; }
#mcpt * { box-sizing:border-box; }
#mcpt .k-link { color:#2563eb; cursor:pointer; text-decoration:none; }
#mcpt .k-link:hover { text-decoration:underline; }
#mcpt .k-bar { display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
#mcpt .k-bar h2 { margin:0 8px 0 0; font-size:16px; font-weight:600; }
#mcpt .k-stat { color:var(--muted); margin-left:auto; }
#mcpt .k-muted { color:var(--muted); }
#mcpt .k-pad { padding:14px; }
#mcpt .k-error { color:#b91c1c; margin:6px 0; }
#mcpt .k-warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:7px 9px; border-radius:5px; margin:8px 0; }
#mcpt input[type=text], #mcpt select { padding:5px 8px; border:1px solid var(--line); border-radius:5px; font-size:13px; max-width:100%; }
#mcpt input[type=text] { width:340px; }
#mcpt button.k-btn { padding:6px 12px; border:1px solid var(--line); background:#fff; border-radius:5px; cursor:pointer; font-size:13px; color:var(--ink); }
#mcpt button.k-btn:hover { background:var(--soft); }
#mcpt button.k-btn[disabled] { opacity:.45; cursor:default; }
#mcpt button.k-primary { background:#eef4ff; border-color:#b9d0fb; color:#1d4ed8; }
#mcpt button.k-danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }

#mcpt .k-intro { padding:6px 14px 0; }
#mcpt .k-intro h3 { margin:10px 0 4px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
#mcpt .k-scopes { margin:0 0 6px; padding-left:18px; }
#mcpt .k-scopes li { margin:4px 0; }
#mcpt .k-scope { display:inline-block; font-size:11px; font-weight:600; padding:1px 7px; border-radius:9px; background:#eef2f7; }
#mcpt .k-scope-read { background:#e8eef6; color:#475569; }
#mcpt .k-scope-content { background:#e3f6e8; color:#166534; }
#mcpt .k-scope-develop { background:#e0ecff; color:#1d4ed8; }
#mcpt .k-scope-admin { background:#fee2e2; color:#b91c1c; }

#mcpt .k-form { padding:10px 14px 14px; border:1px solid var(--line); border-left:3px solid #2563eb; border-radius:6px; margin:12px 14px; background:var(--soft); }
#mcpt .k-form h3 { margin:0 0 8px; font-size:14px; }
#mcpt .k-field { display:flex; flex-direction:column; gap:2px; margin:8px 0; }
#mcpt .k-flabel { color:var(--muted); font-size:11px; }
#mcpt .k-check { display:block; margin:6px 0; cursor:pointer; }
#mcpt .k-details { margin:8px 0; }
#mcpt .k-details summary { cursor:pointer; color:#2563eb; }
#mcpt .k-groups { columns:2; margin-top:6px; }
#mcpt .k-actions { display:flex; gap:8px; margin-top:10px; }

#mcpt .k-result:not(:empty) { margin:12px 14px; padding:12px 14px; border:2px solid #86c58a; background:#f3faf3; border-radius:6px; }
#mcpt .k-resulthead { display:flex; align-items:center; justify-content:space-between; }
#mcpt .k-resulthead h3 { margin:0; font-size:15px; }
#mcpt .k-copyblock { margin:8px 0; }
#mcpt .k-copyhead { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
#mcpt .k-pre { margin:0; padding:8px 10px; background:#fff; border:1px solid var(--line); border-radius:5px; white-space:pre-wrap; word-break:break-all; font-size:12px; user-select:all; }
#mcpt .k-token { font-size:13px; font-weight:600; }

#mcpt .k-tablebox { padding:0 14px; }
#mcpt .k-toggle { padding:8px 14px; margin:0; }
#mcpt .k-table { width:100%; border-collapse:collapse; }
#mcpt .k-table th { text-align:left; color:var(--muted); font-weight:500; font-size:11px; padding:7px 8px; border-bottom:1px solid var(--line); }
#mcpt .k-table td { padding:7px 8px; border-bottom:1px solid #eef1f5; vertical-align:top; }
#mcpt .k-table tr.k-inactive td { color:#aaa; }
#mcpt .k-table tr.k-editrow > td { padding:0; background:#fff; }
#mcpt .k-nowrap { white-space:nowrap; }
#mcpt .k-num { color:var(--muted); font-variant-numeric:tabular-nums; }
#mcpt .k-label { font-weight:600; word-break:break-word; }
#mcpt .k-group { font-size:11px; background:#eef2f7; border-radius:3px; padding:0 5px; }
#mcpt .k-status { font-size:11px; padding:1px 6px; border-radius:9px; background:#eef2f7; }
#mcpt .k-status-active { background:#e3f6e8; color:#166534; }
#mcpt .k-status-expired { background:#fff3d6; color:#92400e; }
#mcpt .k-status-revoked { background:#fee2e2; color:#b91c1c; }
</style>
{/literal}
<div id="mcpt"></div>
