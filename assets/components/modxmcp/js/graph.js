/**
 * modxMCP — dependency graph screen.
 *
 * Force-directed map of how the site's elements wire together, drawn on a plain canvas.
 * Deliberately dependency-free: the MODX manager ships no graph library and the transport
 * package must stay self-contained.
 *
 * The screen is built around the questions this graph actually answers:
 *   "what breaks if I touch X"  -> select a node, or isolate its INCOMING neighbourhood
 *   "what is this template made of" -> isolate its OUTGOING neighbourhood
 *   "where is the dead code"    -> the orphans list, clickable onto the canvas
 *   "what is already broken"    -> the missing list + hollow red nodes
 */
Ext.onReady(function () {
    var root = document.getElementById('mcpg');
    if (!root || typeof MODx === 'undefined' || !MODx.Ajax) { return; }

    var cfg = (typeof ModxmcpGraph !== 'undefined') ? ModxmcpGraph : {};

    var labels = cfg.l || {};
    function t(key) { return labels[key] || key; }
    function h(key) { return esc(t(key)); }

    var COLORS = {
        template: '#2563eb', chunk: '#16a34a', snippet: '#d97706',
        tv: '#9333ea', plugin: '#dc2626', resource: '#64748b', missing: '#ef4444'
    };
    var LABEL = {
        template: t('templates'), chunk: t('chunks'), snippet: t('snippets'), tv: 'TV',
        plugin: t('plugins'), resource: t('resources'), missing: t('broken_links')
    };
    var ONE = {
        template: t('template'), chunk: t('chunk'), snippet: t('snippet'), tv: 'TV',
        plugin: t('plugin'), resource: t('resource'), missing: t('missing')
    };
    var EDIT = {
        template: 'element/template/update', chunk: 'element/chunk/update',
        snippet: 'element/snippet/update', tv: 'element/tv/update',
        plugin: 'element/plugin/update', resource: 'resource/update'
    };
    var KIND = {
        tag: t('tag'), attached: t('attached'), property: t('property'), php: 'PHP',
        migx: 'MIGX', binding: t('binding'), link: t('link'), template: t('template'), missing: t('broken')
    };

    var $ = function (id) { return document.getElementById(id); };
    var canvas = $('g-canvas'), ctx = canvas.getContext('2d');
    var overlay = $('g-overlay'), crumb = $('g-crumb'), info = $('g-info'), stat = $('g-stat');

    // ---- state ------------------------------------------------------------------------
    var raw = null;
    var N = [];            // every node (server nodes + synthesised "missing" ghosts)
    var L = [];            // every link
    var adjOut = {}, adjIn = {}, adjAll = {};
    var vis = [];          // vis[i] === true when node i is currently shown
    var visN = [], visL = [];
    var typeOn = {};
    var focus = null, depth = 1, dir = 'both';
    var onlyIssues = false;
    var selected = null, hovered = null, dragging = null, panning = null;
    // Rows follow the manager's own tree order (Resources, then Elements: templates, TVs,
    // chunks, snippets, plugins) so the graph matches where things live in the UI. Broken
    // references have no home in that tree, so they sit at the bottom.
    var LANE_ORDER = ['resource', 'template', 'tv', 'chunk', 'snippet', 'plugin', 'missing'];
    // Base geometry, scaled live by the density slider. Repulsion falls off as 1/d², so it is
    // scaled by spread² to keep the same visual separation as distances grow.
    var BASE_ROW_GAP = 215, BASE_LINK = 140, BASE_REP = 4200, BASE_CELL = 170;
    var spread = 1;
    function rowGap() { return BASE_ROW_GAP * spread; }
    var layout = 'lanes';
    var lanes = {}, anchors = {};
    var hideVendor = false, hideOrphans = false, vendorUnusedCount = 0;
    var view = { x: 0, y: 0, k: 1 };
    var alpha = 0, raf = null;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function mgrUrl(type, id) {
        var base = cfg.manager_url || ((typeof MODx !== 'undefined' && MODx.config) ? MODx.config.manager_url : '');
        return (base && EDIT[type] && id) ? (base + '?a=' + EDIT[type] + '&id=' + id) : null;
    }
    function dot(type) {
        return type === 'missing'
            ? '<span class="dot hollow"></span>'
            : '<span class="dot" style="background:' + (COLORS[type] || '#94a3b8') + '"></span>';
    }

    // ---- load -------------------------------------------------------------------------
    function load() {
        overlay.style.display = 'flex';
        overlay.textContent = t('loading_graph');
        stat.textContent = t('loading');
        MODx.Ajax.request({
            url: cfg.connector_url,
            params: {
                action: 'mgr/graph',
                include_resources: $('g-res-nodes').checked ? 'true' : 'false'
            },
            listeners: {
                success: { fn: function (r) {
                    raw = (r && r.object) ? r.object : null;
                    if (!raw || !raw.nodes) { fail(t('empty_response')); return; }
                    ingest();
                } },
                failure: { fn: function (r) {
                    fail((r && r.message) ? r.message : t('load_failed'));
                } }
            }
        });
    }
    function fail(msg) {
        overlay.style.display = 'flex';
        overlay.textContent = msg;
        stat.textContent = t('error');
    }

    // Turn the payload into simulation nodes. "missing" targets become hollow ghost nodes so a
    // broken reference is visible in the picture, not only in a list.
    function ingest() {
        N = []; L = [];
        var byKey = {};
        (raw.nodes || []).forEach(function (n) {
            var i = N.length;
            N.push({ i: i, src: n, type: n.type, name: n.name, id: n.id,
                     deg: (n['in'] || 0) + (n.out || 0), orphan: false,
                     x: 0, y: 0, vx: 0, vy: 0 });
            byKey[n.type + ':' + n.name] = i;
        });
        (raw.edges || []).forEach(function (e) { L.push({ s: e[0], t: e[1], kind: e[2] }); });

        var ghosts = {};
        (raw.missing || []).forEach(function (m) {
            var from = byKey[m.from];
            if (from === undefined) { return; }
            var key = m.kind + ':' + m.name;
            if (ghosts[key] === undefined) {
                ghosts[key] = N.length;
                N.push({ i: N.length, src: { type: 'missing', name: m.name, missing_kind: m.kind, 'in': 1, out: 0 },
                         type: 'missing', name: m.name, id: 0, deg: 1, orphan: false,
                         x: 0, y: 0, vx: 0, vy: 0 });
            }
            L.push({ s: from, t: ghosts[key], kind: 'missing' });
        });

        (raw.orphans || []).forEach(function (o) {
            var i = byKey[o.type + ':' + o.name];
            if (i !== undefined) { N[i].orphan = true; N[i].orphanReason = o.reason; }
        });

        adjOut = {}; adjIn = {}; adjAll = {};
        L.forEach(function (l) {
            (adjOut[l.s] || (adjOut[l.s] = [])).push(l.t);
            (adjIn[l.t] || (adjIn[l.t] = [])).push(l.s);
            (adjAll[l.s] || (adjAll[l.s] = [])).push(l.t);
            (adjAll[l.t] || (adjAll[l.t] = [])).push(l.s);
        });

        vendorUnusedCount = computeVendorUse();

        // Type filters keep their state across reloads; new types default to on.
        var counts = {};
        N.forEach(function (n) { counts[n.type] = (counts[n.type] || 0) + 1; });
        Object.keys(counts).forEach(function (t) { if (typeOn[t] === undefined) { typeOn[t] = true; } });

        renderTypes(counts);
        renderIssues();
        renderLegend(counts);
        seed();
        focus = null; selected = null;
        apply(false);
        relayout();
        overlay.style.display = 'none';
    }

    // A vendor element matters only if THIS site reaches it. Walk out-edges from every
    // non-vendor node: whatever is reached is in use (pdoResources, pulled in by a template,
    // stays); whatever is left is an add-on's own internal wiring — pure noise on the canvas.
    function computeVendorUse() {
        var seen = {}, stack = [];
        N.forEach(function (n) { if (!(n.src && n.src.vendor)) { stack.push(n.i); } });
        while (stack.length) {
            var cur = stack.pop();
            if (seen[cur]) { continue; }
            seen[cur] = true;
            var nb = adjOut[cur];
            if (!nb) { continue; }
            for (var k = 0; k < nb.length; k++) { if (!seen[nb[k]]) { stack.push(nb[k]); } }
        }
        var unused = 0;
        N.forEach(function (n) {
            n.vendorUnused = !!(n.src && n.src.vendor) && !seen[n.i];
            if (n.vendorUnused) { unused++; }
        });
        return unused;
    }

    function seed() {
        var w = canvas.clientWidth || 900, h = canvas.clientHeight || 600;
        var rad = Math.min(w, h) * 0.42;
        N.forEach(function (n, i) {
            var a = (i / Math.max(1, N.length)) * Math.PI * 2;
            var jitter = 0.55 + ((i * 37) % 100) / 200;
            n.x = w / 2 + Math.cos(a) * rad * jitter;
            n.y = h / 2 + Math.sin(a) * rad * jitter;
            n.vx = n.vy = 0;
        });
    }

    // Where each type lives. Only types actually on screen get a lane / anchor, so hiding a
    // type closes its column instead of leaving a hole.
    function computeSlots() {
        lanes = {}; anchors = {};
        var present = {};
        visN.forEach(function (n) { present[n.type] = (present[n.type] || 0) + 1; });
        var order = LANE_ORDER.filter(function (t) { return present[t]; });
        if (!order.length) { return; }

        var w = canvas.clientWidth || 900, h = canvas.clientHeight || 600;
        var span = (order.length - 1) * rowGap();
        var rad = Math.min(w, h) * 0.34;
        order.forEach(function (t, i) {
            lanes[t] = { y: h / 2 - span / 2 + i * rowGap(), n: present[t] };
            var a = (i / order.length) * Math.PI * 2 - Math.PI / 2;
            anchors[t] = { x: w / 2 + Math.cos(a) * rad, y: h / 2 + Math.sin(a) * rad, n: present[t] };
        });
    }

    // Switching layout re-seeds into the new arrangement so the simulation converges in a
    // second instead of slowly migrating across the screen.
    function relayout() {
        computeSlots();
        var w = canvas.clientWidth || 900, h = canvas.clientHeight || 600;
        if (layout !== 'free') {
            visN.forEach(function (n, i) {
                if (layout === 'lanes') {
                    var l = lanes[n.type] || { y: h / 2 };
                    n.y = l.y + (((i * 37) % 40) - 20);
                    n.x = 60 + ((i * 89) % Math.max(200, w - 140));
                } else {
                    var a = anchors[n.type] || { x: w / 2, y: h / 2 };
                    n.x = a.x + (((i * 53) % 130) - 65);
                    n.y = a.y + (((i * 31) % 130) - 65);
                }
                n.vx = n.vy = 0;
            });
        }
        alpha = 1;
        kick();
        setTimeout(fit, 420);
    }

    // ---- visibility -------------------------------------------------------------------
    function bfs(start, d, direction) {
        var map = direction === 'out' ? adjOut : (direction === 'in' ? adjIn : adjAll);
        var seen = {}; seen[start] = true;
        var frontier = [start];
        for (var step = 0; step < d && frontier.length; step++) {
            var next = [];
            for (var i = 0; i < frontier.length; i++) {
                var nb = map[frontier[i]];
                if (!nb) { continue; }
                for (var j = 0; j < nb.length; j++) {
                    if (!seen[nb[j]]) { seen[nb[j]] = true; next.push(nb[j]); }
                }
            }
            frontier = next;
        }
        return seen;
    }

    function apply(refit) {
        var inFocus = (focus !== null) ? bfs(focus, depth, dir) : null;

        var issueSet = null;
        if (onlyIssues) {
            issueSet = {};
            N.forEach(function (n) {
                if (n.type === 'missing' || n.orphan) {
                    issueSet[n.i] = true;
                    (adjAll[n.i] || []).forEach(function (j) { issueSet[j] = true; });
                }
            });
        }

        vis = new Array(N.length);
        visN = [];
        for (var i = 0; i < N.length; i++) {
            var n = N[i];
            var ok = typeOn[n.type] !== false;
            if (ok && hideVendor && n.vendorUnused) { ok = false; }
            if (ok && hideOrphans && n.orphan) { ok = false; }
            if (ok && inFocus && !inFocus[i]) { ok = false; }
            if (ok && issueSet && !issueSet[i]) { ok = false; }
            vis[i] = ok;
            if (ok) { visN.push(n); }
        }
        visL = L.filter(function (l) { return vis[l.s] && vis[l.t]; });
        computeSlots();

        var typeHidden = Object.keys(typeOn).some(function (t) { return typeOn[t] === false; });
        $('g-reset').disabled = (focus === null && !onlyIssues && !typeHidden && !hideVendor && !hideOrphans);
        renderCrumb();
        updateStat();
        if (selected !== null && !vis[selected]) { select(null); }
        alpha = 1;
        kick();
        if (refit) { setTimeout(fit, 350); }
    }

    function updateStat() {
        if (!raw) { return; }
        var s = raw.stats || {};
        var shown = visN.length, total = N.length;
        stat.textContent = (shown === total ? (total + t('nodes')) : (shown + t('of_total') + total + t('nodes')))
            + ' · ' + visL.length + t('edges')
            + (s.missing ? ' · ' + s.missing + t('broken_count') : '')
            + (s.orphans ? ' · ' + s.orphans + t('unused_count') : '');
    }

    // ---- sidebar ----------------------------------------------------------------------
    function renderTypes(counts) {
        var html = '';
        ['template', 'chunk', 'snippet', 'tv', 'plugin', 'resource', 'missing'].forEach(function (t) {
            if (!counts[t]) { return; }
            html += '<label><input type="checkbox" data-type="' + t + '"' + (typeOn[t] !== false ? ' checked' : '') + '>'
                 + dot(t) + '<span class="c">' + esc(LABEL[t]) + '</span><span class="n">' + counts[t] + '</span></label>';
        });
        $('g-types').innerHTML = html;
        Array.prototype.forEach.call($('g-types').querySelectorAll('input'), function (b) {
            b.addEventListener('change', function () {
                typeOn[b.getAttribute('data-type')] = b.checked;
                apply(false);
            });
        });
    }

    function renderLegend(counts) {
        var html = '';
        ['template', 'chunk', 'snippet', 'tv', 'plugin', 'resource', 'missing'].forEach(function (t) {
            if (counts[t]) { html += '<span>' + dot(t) + esc(LABEL[t]) + '</span>'; }
        });
        $('g-legend').innerHTML = html;
    }

    function renderIssues() {
        var miss = raw.missing || [], orph = raw.orphans || [];
        $('g-nmiss').textContent = miss.length;
        $('g-norph').textContent = orph.length;

        var byKey = {};
        N.forEach(function (n) { byKey[n.type + ':' + n.name] = n.i; });

        var mh = '';
        miss.forEach(function (m) {
            var idx = byKey[m.kind + ':' + m.name];
            mh += '<li data-go="' + (idx === undefined ? '' : idx) + '">' + dot('missing')
               + '<span><b>' + esc(m.name) + ('</b><br><span class="t">' + h('from')) + esc(m.from) + '</span></span></li>';
        });
        $('g-miss').innerHTML = mh || ('<li class="g-empty-note">' + h('no_broken') + '</li>');

        var oh = '';
        orph.forEach(function (o) {
            var idx = byKey[o.type + ':' + o.name];
            oh += '<li data-go="' + (idx === undefined ? '' : idx) + '">' + dot(o.type)
               + '<span>' + esc(o.name) + '<br><span class="t">' + esc(o.reason) + '</span></span></li>';
        });
        $('g-orph').innerHTML = oh || ('<li class="g-empty-note">' + h('all_used') + '</li>');

        $('g-nvendor').textContent = vendorUnusedCount;
        $('g-norph2').textContent = orph.length;

        $('g-orph-note').textContent = (raw.stats && raw.stats.orphans_verified === false)
            ? t('orphan_unverified')
            : t('orphan_note');

        [['g-miss'], ['g-orph']].forEach(function (p) {
            Array.prototype.forEach.call($(p[0]).querySelectorAll('li[data-go]'), function (li) {
                li.addEventListener('click', function () {
                    var i = parseInt(li.getAttribute('data-go'), 10);
                    if (isNaN(i)) { return; }
                    revealNode(i);
                    select(i); center(i);
                });
            });
        });
    }

    function syncTypeBoxes() {
        Array.prototype.forEach.call($('g-types').querySelectorAll('input'), function (b) {
            b.checked = typeOn[b.getAttribute('data-type')] !== false;
        });
    }

    // Clicking a node in any list must actually show it — clear whichever filter hides it.
    function revealNode(i) {
        if (vis[i]) { return; }
        typeOn[N[i].type] = true;
        if (N[i].vendorUnused) { hideVendor = false; $('g-hide-vendor').checked = false; }
        if (N[i].orphan) { hideOrphans = false; $('g-hide-orphans').checked = false; }
        focus = null;
        onlyIssues = false; $('g-only-issues').checked = false;
        syncTypeBoxes();
        apply(false);
    }

    // ---- isolation --------------------------------------------------------------------
    function isolate(i, d, direction) {
        focus = i; depth = d || 1; dir = direction || 'both';
        onlyIssues = false; $('g-only-issues').checked = false;
        apply(true);
    }
    function renderCrumb() {
        if (focus === null) { crumb.style.display = 'none'; return; }
        var n = N[focus];
        var dbtn = function (v) { return '<button class="g-btn' + (depth === v ? ' on' : '') + '" data-d="' + v + '">' + v + '</button>'; };
        var rbtn = function (v, t) { return '<button class="g-btn' + (dir === v ? ' on' : '') + '" data-r="' + v + '">' + t + '</button>'; };
        crumb.innerHTML = dot(n.type) + '<b>' + esc(n.name) + '</b>'
            + ('<span style="color:#7b8794;">' + h('hops') + '</span>') + dbtn(1) + dbtn(2) + dbtn(3)
            + rbtn('both', h('both')) + rbtn('out', h('uses')) + rbtn('in', h('used'))
            + '<button class="g-btn" data-x="1">✕</button>';
        crumb.style.display = 'flex';
        Array.prototype.forEach.call(crumb.querySelectorAll('button'), function (b) {
            b.addEventListener('click', function () {
                if (b.getAttribute('data-x')) { focus = null; apply(true); return; }
                if (b.getAttribute('data-d')) { depth = parseInt(b.getAttribute('data-d'), 10); }
                if (b.getAttribute('data-r')) { dir = b.getAttribute('data-r'); }
                apply(true);
            });
        });
    }

    // ---- physics ----------------------------------------------------------------------
    // Repulsion uses a uniform grid so a big site (thousands of elements) stays interactive;
    // a plain all-pairs loop would be O(n²) per frame.
    function tick() {
        var n = visN.length;
        if (!n) { alpha = 0; return; }
        var w = canvas.clientWidth || 900, h = canvas.clientHeight || 600;
        var cell = BASE_CELL * spread, buckets = {}, i, j, a, b;

        for (i = 0; i < n; i++) {
            a = visN[i];
            var key = Math.floor(a.x / cell) + ',' + Math.floor(a.y / cell);
            (buckets[key] || (buckets[key] = [])).push(a);
        }
        for (i = 0; i < n; i++) {
            a = visN[i];
            var kx = Math.floor(a.x / cell), ky = Math.floor(a.y / cell);
            for (var dx = -1; dx <= 1; dx++) {
                for (var dy = -1; dy <= 1; dy++) {
                    var arr = buckets[(kx + dx) + ',' + (ky + dy)];
                    if (!arr) { continue; }
                    for (j = 0; j < arr.length; j++) {
                        b = arr[j];
                        if (b === a) { continue; }
                        var rx = a.x - b.x, ry = a.y - b.y;
                        var d2 = rx * rx + ry * ry;
                        if (d2 < 0.01) { d2 = 0.01; rx = (i % 5) - 2; ry = (j % 5) - 2; }
                        var f = (BASE_REP * spread * spread) / d2, d = Math.sqrt(d2);
                        a.vx += (rx / d) * f;
                        a.vy += (ry / d) * f;
                    }
                }
            }
        }

        for (i = 0; i < visL.length; i++) {
            var s = N[visL[i].s], t = N[visL[i].t];
            var lx = t.x - s.x, ly = t.y - s.y;
            var ld = Math.sqrt(lx * lx + ly * ly) || 0.01;
            var lf = (ld - BASE_LINK * spread) * 0.016;
            s.vx += (lx / ld) * lf; s.vy += (ly / ld) * lf;
            t.vx -= (lx / ld) * lf; t.vy -= (ly / ld) * lf;
        }

        for (i = 0; i < n; i++) {
            a = visN[i];
            if (layout === 'lanes') {
                // y is pinned to the type's row; x stays free, so links still pull related
                // nodes into vertical alignment and repulsion spreads the row horizontally.
                var lane = lanes[a.type];
                if (lane) { a.vy += (lane.y - a.y) * 0.22; }
                a.vx += (w / 2 - a.x) * 0.0035;
            } else if (layout === 'clusters') {
                var an = anchors[a.type];
                if (an) { a.vx += (an.x - a.x) * 0.035; a.vy += (an.y - a.y) * 0.035; }
            } else {
                a.vx += (w / 2 - a.x) * 0.005;
                a.vy += (h / 2 - a.y) * 0.005;
            }
            if (a === dragging) { a.vx = a.vy = 0; continue; }
            a.vx *= 0.85; a.vy *= 0.85;
            a.x += a.vx * alpha; a.y += a.vy * alpha;
        }
        separate();
        separate();
        pinRows();

        alpha *= 0.986;
        if (alpha < 0.008) { alpha = 0; }
    }

    // Rows are a POSITIONAL constraint, applied after collisions and at the same full strength.
    // Every other force acts through velocity and is scaled by a decaying alpha, so once the
    // simulation cooled, collisions — which move positions directly — were the only thing still
    // acting, and they squeezed nodes out of their row with nothing left to pull them back.
    function pinRows() {
        if (layout !== 'lanes') { return; }
        for (var i = 0; i < visN.length; i++) {
            var a = visN[i];
            if (a === dragging) { continue; }
            var lane = lanes[a.type];
            if (lane) { a.y += (lane.y - a.y) * 0.6; }
        }
    }

    // Hard minimum gap between nodes. Repulsion alone can't guarantee it: it falls off as 1/d²,
    // and when a dozen chunks hang off one template the springs park them all on the same radius,
    // where they sit on top of each other. This resolves overlap positionally — two passes, so
    // a node squeezed out of one collision doesn't end up inside the next.
    function separate() {
        var n = visN.length;
        if (n < 2) { return; }
        var pad = 16 * spread, i, j, a, b, maxR = 0;
        for (i = 0; i < n; i++) { var r = radius(visN[i]); if (r > maxR) { maxR = r; } }
        var cell = (maxR * 2 + pad) * 1.05, buckets = {};
        for (i = 0; i < n; i++) {
            a = visN[i];
            var key = Math.floor(a.x / cell) + ',' + Math.floor(a.y / cell);
            (buckets[key] || (buckets[key] = [])).push(a);
        }
        for (i = 0; i < n; i++) {
            a = visN[i];
            var ar = radius(a), kx = Math.floor(a.x / cell), ky = Math.floor(a.y / cell);
            for (var dx = -1; dx <= 1; dx++) {
                for (var dy = -1; dy <= 1; dy++) {
                    var arr = buckets[(kx + dx) + ',' + (ky + dy)];
                    if (!arr) { continue; }
                    for (j = 0; j < arr.length; j++) {
                        b = arr[j];
                        if (b.i <= a.i) { continue; }        // each pair once
                        var rx = b.x - a.x, ry = b.y - a.y;
                        var min = ar + radius(b) + pad;
                        var d2 = rx * rx + ry * ry;
                        if (d2 >= min * min) { continue; }
                        if (d2 < 0.0001) { rx = (a.i % 7) - 3 || 1; ry = (b.i % 7) - 3 || 1; d2 = rx * rx + ry * ry; }
                        var d = Math.sqrt(d2);
                        var push = ((min - d) / d) * 0.5;
                        var px = rx * push, py = ry * push;
                        if (a !== dragging) { a.x -= px; a.y -= py; }
                        if (b !== dragging) { b.x += px; b.y += py; }
                    }
                }
            }
        }
    }

    // ---- render -----------------------------------------------------------------------
    function radius(n) { return 4.5 + Math.sqrt(n.deg) * 1.9; }
    function sx(n) { return n.x * view.k + view.x; }
    function sy(n) { return n.y * view.k + view.y; }

    // Keep the backing store in step with the CSS box. The canvas box changes for reasons that
    // are NOT a window resize — the details panel opening, the sidebar, a browser zoom changing
    // devicePixelRatio — and when it does, clearRect() stops covering the whole surface and
    // stale pixels survive along the edge. Checking on every frame is a couple of property
    // reads and makes the canvas self-healing whatever the cause.
    function ensureSize() {
        var dpr = window.devicePixelRatio || 1;
        var w = canvas.clientWidth, h = canvas.clientHeight;
        if (!w || !h) { return; }
        var bw = Math.round(w * dpr), bh = Math.round(h * dpr);
        if (canvas.width !== bw || canvas.height !== bh) {
            canvas.width = bw;
            canvas.height = bh;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
    }

    function draw() {
        ensureSize();
        var w = canvas.clientWidth, h = canvas.clientHeight;
        // Clear in device pixels under an identity transform, so the wipe always covers the
        // entire surface regardless of the current scale/translate.
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
        if (!visN.length) { return; }

        // Row bands sit under everything so the rows read as regions, not as decoration.
        var band = rowGap() * 0.9 * view.k;
        if (layout === 'lanes') {
            Object.keys(lanes).forEach(function (t) {
                var cy = lanes[t].y * view.k + view.y;
                if (cy < -band || cy > h + band) { return; }
                ctx.globalAlpha = 0.055;
                ctx.fillStyle = COLORS[t] || '#94a3b8';
                ctx.fillRect(0, cy - band / 2, w, band);
                ctx.globalAlpha = 1;
            });
        }

        var hi = (selected !== null && vis[selected]) ? selected : ((hovered !== null && vis[hovered]) ? hovered : null);
        var near = null;
        if (hi !== null) {
            near = {};
            (adjAll[hi] || []).forEach(function (j) { near[j] = true; });
        }

        visL.forEach(function (l) {
            var s = N[l.s], t = N[l.t];
            var lit = hi !== null && (l.s === hi || l.t === hi);
            var x1 = sx(s), y1 = sy(s), x2 = sx(t), y2 = sy(t);
            var dx = x2 - x1, dy = y2 - y1, d = Math.sqrt(dx * dx + dy * dy) || 1;
            var tr = Math.max(3, radius(t) * Math.sqrt(view.k));
            var ex = x2 - (dx / d) * (tr + 2.5), ey = y2 - (dy / d) * (tr + 2.5);

            if (l.kind === 'missing') {
                ctx.strokeStyle = lit ? 'rgba(239,68,68,.95)' : 'rgba(239,68,68,.5)';
                ctx.setLineDash([4, 3]);
            } else {
                ctx.strokeStyle = lit ? 'rgba(55,65,81,.85)' : (hi !== null ? 'rgba(148,163,184,.14)' : 'rgba(148,163,184,.4)');
                ctx.setLineDash([]);
            }
            ctx.lineWidth = lit ? 1.7 : 1;
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(ex, ey);
            ctx.stroke();

            // Arrowhead: the graph reads "from USES to", and that direction is the whole point.
            if (view.k > 0.55 || lit) {
                var ang = Math.atan2(dy, dx), head = lit ? 7 : 5.5;
                ctx.fillStyle = ctx.strokeStyle;
                ctx.beginPath();
                ctx.moveTo(ex, ey);
                ctx.lineTo(ex - head * Math.cos(ang - 0.42), ey - head * Math.sin(ang - 0.42));
                ctx.lineTo(ex - head * Math.cos(ang + 0.42), ey - head * Math.sin(ang + 0.42));
                ctx.closePath();
                ctx.fill();
            }
        });
        ctx.setLineDash([]);

        var showAllLabels = visN.length <= 90 || view.k > 1.25;
        visN.forEach(function (n) {
            var i = n.i, p = { x: sx(n), y: sy(n) }, r = Math.max(3, radius(n) * Math.sqrt(view.k));
            var lit = hi === null || i === hi || (near && near[i]);
            ctx.globalAlpha = lit ? 1 : 0.2;

            ctx.beginPath();
            ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
            if (n.type === 'missing') {
                ctx.fillStyle = '#fff'; ctx.fill();
                ctx.strokeStyle = COLORS.missing; ctx.lineWidth = 2; ctx.stroke();
            } else {
                ctx.fillStyle = COLORS[n.type] || '#94a3b8';
                ctx.fill();
                if (n.orphan) {                       // dead-code candidate: dashed halo
                    ctx.setLineDash([2, 2]);
                    ctx.strokeStyle = 'rgba(100,116,139,.85)';
                    ctx.lineWidth = 1.2;
                    ctx.beginPath(); ctx.arc(p.x, p.y, r + 3.5, 0, Math.PI * 2); ctx.stroke();
                    ctx.setLineDash([]);
                }
                if (i === selected) {
                    ctx.strokeStyle = '#111827'; ctx.lineWidth = 2.5;
                    ctx.beginPath(); ctx.arc(p.x, p.y, r + 1.5, 0, Math.PI * 2); ctx.stroke();
                }
            }

            if (lit && (showAllLabels || i === hi || (near && near[i]) || r > 10)) {
                ctx.fillStyle = '#374151';
                ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif';
                ctx.textAlign = 'center';
                var t = n.name.length > 28 ? n.name.slice(0, 27) + '…' : n.name;
                ctx.fillText(t, p.x, p.y - r - 5);
            }
            ctx.globalAlpha = 1;
        });

        // Row headers last, so nodes never cover them. They stick to the left edge of the
        // viewport rather than to the world, so the row stays labelled while you pan sideways.
        if (layout === 'lanes') {
            ctx.textAlign = 'left';
            ctx.font = '600 11px -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif';
            Object.keys(lanes).forEach(function (t) {
                var cy = lanes[t].y * view.k + view.y;
                var top = cy - band / 2;
                if (top < -22 || top > h) { return; }
                var label = (LABEL[t] || t) + ' · ' + lanes[t].n;
                var tw = ctx.measureText(label).width;
                ctx.fillStyle = 'rgba(255,255,255,.88)';
                ctx.fillRect(8, Math.max(2, top + 4), tw + 12, 18);
                ctx.fillStyle = COLORS[t] || '#64748b';
                ctx.fillText(label, 14, Math.max(2, top + 4) + 13);
            });
        }
    }

    function loop() { if (alpha > 0) { tick(); } draw(); raf = (alpha > 0) ? requestAnimationFrame(loop) : null; }
    function kick() { if (!raf) { raf = requestAnimationFrame(loop); } }

    function fit() {
        if (!visN.length) { return; }
        var minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;
        visN.forEach(function (n) {
            if (n.x < minx) { minx = n.x; } if (n.x > maxx) { maxx = n.x; }
            if (n.y < miny) { miny = n.y; } if (n.y > maxy) { maxy = n.y; }
        });
        var w = canvas.clientWidth, h = canvas.clientHeight, pad = 60;
        var k = Math.min((w - pad * 2) / Math.max(1, maxx - minx), (h - pad * 2) / Math.max(1, maxy - miny));
        view.k = Math.min(2.2, Math.max(0.15, k));
        view.x = w / 2 - ((minx + maxx) / 2) * view.k;
        view.y = h / 2 - ((miny + maxy) / 2) * view.k;
        kick();
    }
    function center(i) {
        if (!N[i]) { return; }
        view.k = Math.max(view.k, 1.15);
        view.x = canvas.clientWidth / 2 - N[i].x * view.k;
        view.y = canvas.clientHeight / 2 - N[i].y * view.k;
        kick();
    }

    // ---- details ----------------------------------------------------------------------
    function select(i) {
        selected = i;
        if (i === null) { info.className = 'g-info'; info.innerHTML = ''; kick(); return; }
        var n = N[i], src = n.src;
        var out = [], inc = [];
        L.forEach(function (l) {
            if (l.s === i) { out.push({ i: l.t, kind: l.kind }); }
            else if (l.t === i) { inc.push({ i: l.s, kind: l.kind }); }
        });

        var html = '<h3>' + esc(n.name) + '</h3><div class="meta">' + esc(ONE[n.type] || n.type);
        if (n.id) { html += ' · id ' + n.id; }
        if (src.category) { html += ' · ' + esc(src.category); }
        if (src.tv_type) { html += ' · ' + esc(src.tv_type); }
        if (src.static) { html += ' · static'; }
        if (src.disabled) { html += (' · ' + h('disabled')); }
        html += '</div>';

        if (n.type === 'missing') {
            html += ('<div class="warn"><b>' + h('broken_link') + '</b> ') + esc(src.missing_kind === 'snippet' ? t('snippet_title') : t('chunk_title'))
                 + (h('missing_note') + '</div>');
        }
        if (n.orphan) {
            html += ('<div class="note"><b>' + h('no_references') + '</b> ') + esc(n.orphanReason || '')
                 + (h('dry_run') + '</div>');
        }
        if (src.used_by) { html += ('<div class="note">' + h('used_elsewhere')) + esc(src.used_by) + '</div>'; }

        html += '<div class="acts">';
        var u = mgrUrl(n.type, n.id);
        if (u) { html += '<a class="g-btn" href="' + esc(u) + ('" target="_blank">' + h('edit') + '</a>'); }
        if (n.type !== 'missing') { html += ('<button class="g-btn" data-iso="1">' + h('isolate') + '</button>'); }
        html += '</div>';

        if (typeof src.resources === 'number') { html += ('<div>' + h('template_resources') + '<b>') + src.resources + '</b></div>'; }
        if (src.events && src.events.length) { html += ('<div>' + h('events')) + esc(src.events.join(', ')) + '</div>'; }

        function list(title, arr) {
            if (!arr.length) { return ''; }
            var s = '<h4>' + title + ' (' + arr.length + ')</h4><ul class="g-list">';
            arr.slice(0, 60).forEach(function (x) {
                s += '<li data-go="' + x.i + '">' + dot(N[x.i].type) + '<span>' + esc(N[x.i].name)
                   + ' <span class="t">' + esc(KIND[x.kind] || x.kind) + '</span></span></li>';
            });
            if (arr.length > 60) { s += ('<li class="t">' + h('more')) + (arr.length - 60) + '</li>'; }
            return s + '</ul>';
        }
        html += list(h('uses_heading'), out) + list(h('used_heading'), inc);
        if (!out.length && !inc.length) { html += ('<p class="g-empty-note">' + h('no_edges') + '</p>'); }

        info.innerHTML = html;
        info.className = 'g-info open';
        var iso = info.querySelector('[data-iso]');
        if (iso) { iso.addEventListener('click', function () { isolate(i, depth, dir); }); }
        Array.prototype.forEach.call(info.querySelectorAll('li[data-go]'), function (li) {
            li.addEventListener('click', function () {
                var j = parseInt(li.getAttribute('data-go'), 10);
                revealNode(j);
                select(j); center(j);
            });
        });
        kick();
    }

    // ---- interaction ------------------------------------------------------------------
    function nodeAt(mx, my) {
        for (var i = visN.length - 1; i >= 0; i--) {
            var n = visN[i], r = Math.max(6, radius(n) * Math.sqrt(view.k)) + 3;
            var dx = mx - sx(n), dy = my - sy(n);
            if (dx * dx + dy * dy <= r * r) { return n.i; }
        }
        return null;
    }
    function mouse(e) {
        var b = canvas.getBoundingClientRect();
        return { x: e.clientX - b.left, y: e.clientY - b.top };
    }

    canvas.addEventListener('mousemove', function (e) {
        var m = mouse(e);
        if (dragging !== null) {
            N[dragging].x = (m.x - view.x) / view.k;
            N[dragging].y = (m.y - view.y) / view.k;
            alpha = Math.max(alpha, 0.3); kick(); return;
        }
        if (panning) {
            view.x += m.x - panning.x; view.y += m.y - panning.y;
            panning = { x: m.x, y: m.y }; kick(); return;
        }
        var was = hovered;
        hovered = nodeAt(m.x, m.y);
        canvas.style.cursor = hovered !== null ? 'pointer' : 'grab';
        if (was !== hovered) { kick(); }
    });
    canvas.addEventListener('mousedown', function (e) {
        var m = mouse(e), hit = nodeAt(m.x, m.y);
        if (hit !== null) { dragging = hit; select(hit); }
        else { panning = { x: m.x, y: m.y }; canvas.className = 'drag'; }
        kick();
    });
    window.addEventListener('mouseup', function () { dragging = null; panning = null; canvas.className = ''; });
    canvas.addEventListener('dblclick', function (e) {
        var hit = nodeAt(mouse(e).x, mouse(e).y);
        if (hit !== null && N[hit].type !== 'missing') { isolate(hit, depth, dir); }
    });
    canvas.addEventListener('wheel', function (e) {
        e.preventDefault();
        var m = mouse(e);
        var k = Math.min(6, Math.max(0.12, view.k * (e.deltaY < 0 ? 1.12 : 1 / 1.12)));
        view.x = m.x - (m.x - view.x) * (k / view.k);
        view.y = m.y - (m.y - view.y) * (k / view.k);
        view.k = k; kick();
    }, { passive: false });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if ($('g-res').style.display === 'block') { $('g-res').style.display = 'none'; return; }
        if (selected !== null) { select(null); return; }
        if (focus !== null) { focus = null; apply(true); }
    });

    // ---- search -----------------------------------------------------------------------
    var q = $('g-q'), res = $('g-res');
    q.addEventListener('input', function () {
        var s = q.value.trim().toLowerCase();
        if (!s) { res.style.display = 'none'; return; }
        var hits = N.filter(function (n) { return n.name.toLowerCase().indexOf(s) !== -1; })
                    .sort(function (a, b) {
                        var ai = a.name.toLowerCase().indexOf(s), bi = b.name.toLowerCase().indexOf(s);
                        return ai !== bi ? ai - bi : b.deg - a.deg;
                    }).slice(0, 40);
        if (!hits.length) { res.innerHTML = ('<div style="color:#7b8794;">' + h('no_search') + '</div>'); res.style.display = 'block'; return; }
        res.innerHTML = hits.map(function (n) {
            return '<div data-go="' + n.i + '">' + dot(n.type) + '<span>' + esc(n.name)
                 + ' <span style="color:#7b8794;font-size:11px;">' + esc(ONE[n.type]) + '</span></span></div>';
        }).join('');
        res.style.display = 'block';
        Array.prototype.forEach.call(res.querySelectorAll('[data-go]'), function (d) {
            d.addEventListener('click', function () {
                var i = parseInt(d.getAttribute('data-go'), 10);
                revealNode(i);
                select(i); center(i);
                res.style.display = 'none'; q.value = '';
            });
        });
    });
    document.addEventListener('click', function (e) {
        if (!res.contains(e.target) && e.target !== q) { res.style.display = 'none'; }
    });

    // ---- chrome -----------------------------------------------------------------------
    $('g-reload').addEventListener('click', load);
    $('g-fit').addEventListener('click', fit);
    $('g-reset').addEventListener('click', function () {
        focus = null; onlyIssues = false; $('g-only-issues').checked = false;
        hideVendor = false; $('g-hide-vendor').checked = false;
        hideOrphans = false; $('g-hide-orphans').checked = false;
        Object.keys(typeOn).forEach(function (t) { typeOn[t] = true; });
        syncTypeBoxes(); apply(true);
    });
    $('g-res-nodes').addEventListener('change', load);
    $('g-only-issues').addEventListener('change', function () {
        onlyIssues = $('g-only-issues').checked;
        if (onlyIssues) { focus = null; }
        apply(true);
    });
    $('g-hide-vendor').addEventListener('change', function () {
        hideVendor = $('g-hide-vendor').checked;
        apply(true);
    });
    $('g-hide-orphans').addEventListener('change', function () {
        hideOrphans = $('g-hide-orphans').checked;
        apply(true);
    });
    $('g-spread').addEventListener('input', function () {
        spread = Math.max(0.6, parseInt($('g-spread').value, 10) / 100);
        computeSlots();
        alpha = 1;                 // let the simulation breathe out to the new distances
        kick();
    });
    Array.prototype.forEach.call(root.querySelectorAll('.g-lay'), function (b) {
        b.addEventListener('click', function () {
            var next = b.getAttribute('data-lay');
            if (next === layout) { return; }
            layout = next;
            Array.prototype.forEach.call(root.querySelectorAll('.g-lay'), function (o) {
                o.className = 'g-btn g-lay' + (o === b ? ' on' : '');
            });
            relayout();
        });
    });
    // Size the whole screen to the space the manager actually gives us. Guessing the chrome
    // height with calc(100vh - N) clipped the bottom of the panel — and because the element was
    // exactly viewport-tall there was nothing to scroll to reach it. Measure instead: never
    // taller than what is left below our top edge, and never taller than the content region.
    function fitShell() {
        var rect = root.getBoundingClientRect();
        var avail = window.innerHeight - rect.top - 12;
        var host = document.getElementById('modx-content') || root.parentElement;
        if (host && host !== root) {
            var hr = host.getBoundingClientRect();
            var inHost = hr.bottom - rect.top - 8;
            if (inHost > 240) { avail = Math.min(avail, inHost); }
        }
        if (!isFinite(avail) || avail < 240) { avail = 620; }
        root.style.height = Math.max(420, Math.round(avail)) + 'px';
    }

    // Lane/anchor positions live in WORLD coordinates and are deliberately not recomputed here:
    // re-centring them every time the details panel opens would make the whole graph lurch.
    // Fit to view re-frames on demand.
    window.addEventListener('resize', function () { fitShell(); ensureSize(); kick(); });
    if (window.ResizeObserver) {
        new window.ResizeObserver(function () { ensureSize(); kick(); }).observe(canvas);
    }
    // The manager lays its regions out after Ext.onReady, so measure again once that settles.
    setTimeout(fitShell, 60);
    setTimeout(fitShell, 400);

    fitShell();
    ensureSize();
    load();
});
