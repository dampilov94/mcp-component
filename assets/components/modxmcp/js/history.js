/**
 * modxMCP - history screen (Components > modxMCP > История).
 *
 * Everything that was changed through the API, in the manager or outside the journal: a table,
 * what one change did, and an undo with a plan and a confirmation. Plain ES5 and MODx.Ajax, no libraries.
 *
 * Every value from the site (names, summaries, field values, diffs) is put into the page with
 * textContent only; innerHTML is never used with data. All texts come from the lexicon (ModxmcpHistory.l).
 */
Ext.onReady(function () {
    var root = document.getElementById('mcph');
    if (!root || typeof MODx === 'undefined' || !MODx.Ajax) { return; }

    var cfg = (typeof ModxmcpHistory !== 'undefined') ? ModxmcpHistory : {};
    var L = cfg.l || {};
    var PAGE = 50;

    // ---- helpers ----------------------------------------------------------------------
    function t(key, vars) {
        var text = L.hasOwnProperty(key) ? L[key] : key;
        if (vars) {
            Object.keys(vars).forEach(function (name) { text = text.split('{' + name + '}').join(String(vars[name])); });
        }
        return text;
    }
    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }
    function add(parent) {
        for (var i = 1; i < arguments.length; i++) {
            if (arguments[i]) { parent.appendChild(arguments[i]); }
        }
        return parent;
    }
    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmtTime(unix) {
        var d = new Date(unix * 1000);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function button(label, cls, handler) {
        var b = el('button', 'h-btn' + (cls ? ' ' + cls : ''), label);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
    }
    function link(label, handler) {
        var a = el('a', 'h-link', label);
        a.href = '#';
        a.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); handler(); });
        return a;
    }
    function request(action, params, onOk, onFail) {
        params.action = action;
        MODx.Ajax.request({
            url: cfg.connector_url,
            params: params,
            listeners: {
                success: { fn: function (r) { onOk(r); } },
                failure: { fn: function (r) { if (onFail) { onFail(r || {}); } } }
            }
        });
    }
    function sourceLabel(source) {
        return L.hasOwnProperty('source_' + source) ? L['source_' + source] : source;
    }
    function opLabel(op) {
        return L.hasOwnProperty('op_' + op) ? L['op_' + op] : op;
    }
    function valueText(value) {
        if (value === null || value === undefined) { return '∅'; }
        if (typeof value === 'string') { return value; }
        try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
    }
    function clip(text) {
        return text.length > 600 ? text.substr(0, 600) + '…' : text;
    }
    // "2026-05-01" -> unix time at the start (or end) of that local day
    function dayToUnix(value, end) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) { return ''; }
        var d = end ? new Date(+m[1], +m[2] - 1, +m[3], 23, 59, 59) : new Date(+m[1], +m[2] - 1, +m[3], 0, 0, 0);
        return Math.floor(d.getTime() / 1000);
    }

    // ---- layout -----------------------------------------------------------------------
    var inputs = {};
    function field(label, node) {
        return add(el('label', 'h-field'), el('span', 'h-flabel', label), node);
    }
    function select(options) {
        var s = el('select');
        options.forEach(function (o) {
            var opt = el('option', '', o[1]);
            opt.value = o[0];
            s.appendChild(opt);
        });
        return s;
    }
    function textInput(type, placeholder) {
        var i = el('input');
        i.type = type;
        if (placeholder) { i.placeholder = placeholder; }
        i.addEventListener('keydown', function (e) { if (e.keyCode === 13) { reload(0); } });
        return i;
    }

    inputs.source = select([['', t('source_all')], ['mcp', t('source_mcp')], ['manager', t('source_manager')], ['external', t('source_external')]]);
    inputs.kind = textInput('text', t('kind_ph'));
    inputs.object = textInput('text', '');
    inputs.query = textInput('search', t('query_ph'));
    inputs.since = textInput('date', '');
    inputs.until = textInput('date', '');

    var statEl = el('span', 'h-stat');
    var bar = add(el('div', 'h-bar'),
        el('h2', '', t('heading')),
        field(t('f_source'), inputs.source),
        field(t('f_kind'), inputs.kind),
        field(t('f_object'), inputs.object),
        field(t('f_query'), inputs.query),
        field(t('f_since'), inputs.since),
        field(t('f_until'), inputs.until),
        button(t('refresh'), '', function () { reload(0); }),
        statEl);

    var tableBox = el('div', 'h-tablebox');
    var pager = el('div', 'h-pager');
    var detail = el('div', 'h-detail');
    var side = el('div', 'h-side');
    add(root,
        bar,
        add(el('div', 'h-body'),
            add(el('div', 'h-main'), tableBox, pager, detail),
            side));

    // ---- the table --------------------------------------------------------------------
    var state = { start: 0, total: 0, openId: null };

    function filterParams() {
        var params = {};
        if (inputs.source.value) { params.source = inputs.source.value; }
        if (inputs.kind.value) { params.kind = inputs.kind.value.replace(/^\s+|\s+$/g, ''); }
        if (inputs.object.value) { params.key = inputs.object.value.replace(/^\s+|\s+$/g, ''); }
        if (inputs.query.value) { params.query = inputs.query.value; }
        var since = dayToUnix(inputs.since.value, false), until = dayToUnix(inputs.until.value, true);
        if (since !== '') { params.since = since; }
        if (until !== '') { params.until = until; }
        return params;
    }

    function reload(start) {
        state.start = start;
        statEl.textContent = t('loading');
        var params = filterParams();
        params.start = start;
        params.limit = PAGE;
        request('mgr/history/getlist', params, function (r) {
            state.total = r.total || 0;
            statEl.textContent = '';
            drawTable(r.results || []);
            drawPager();
        }, function (r) {
            statEl.textContent = '';
            clear(tableBox);
            add(tableBox, el('p', 'h-error', t('load_failed') + (r.message ? ' ' + r.message : '')));
            clear(pager);
        });
    }

    function objectsCell(objects) {
        var cell = el('td', 'h-objects');
        (objects || []).slice(0, 3).forEach(function (o) {
            add(cell, add(el('div'), el('span', 'h-kind', o.kind), document.createTextNode(' '), el('span', '', o.name)));
        });
        if (objects && objects.length > 3) { add(cell, el('div', 'h-muted', t('more', { n: objects.length - 3 }))); }
        return cell;
    }

    function statusCell(c) {
        var cell = el('td');
        add(cell, el('span', 'h-status h-' + c.status, t('status_' + c.status)));
        if (c.undone_by) {
            add(cell, document.createTextNode(' '), link(t('undone_by', { n: c.undone_by }), function () { openChangeset(c.undone_by); }));
        }
        return cell;
    }

    function drawTable(rows) {
        clear(tableBox);
        if (!rows.length) {
            add(tableBox, el('p', 'h-muted h-pad', t('empty')));
            return;
        }
        var table = el('table', 'h-table');
        var head = el('tr');
        ['col_id', 'col_time', 'col_source', 'col_author', 'col_tool', 'col_summary', 'col_objects', 'col_status', 'col_reversible'].forEach(function (k) {
            add(head, el('th', '', t(k)));
        });
        add(table, add(el('thead'), head));
        var body = el('tbody');
        rows.forEach(function (c) {
            var tr = el('tr', c.changeset_id === state.openId ? 'sel' : '');
            tr.setAttribute('data-id', c.changeset_id);
            add(tr,
                el('td', 'h-num', c.changeset_id),
                el('td', 'h-nowrap', fmtTime(c.at)),
                add(el('td'), el('span', 'h-src h-src-' + c.source, sourceLabel(c.source))),
                el('td', '', c.author),
                el('td', '', c.tool),
                el('td', 'h-summary', c.summary),
                objectsCell(c.objects),
                statusCell(c),
                el('td', '', c.reversible ? t('reversible_yes') : t('reversible_no')));
            tr.addEventListener('click', function () { openChangeset(c.changeset_id); });
            body.appendChild(tr);
        });
        add(table, body);
        add(tableBox, table);
    }

    function drawPager() {
        clear(pager);
        if (!state.total) { return; }
        var from = state.start + 1, to = Math.min(state.total, state.start + PAGE);
        var prev = button(t('prev'), '', function () { reload(Math.max(0, state.start - PAGE)); });
        var next = button(t('next'), '', function () { reload(state.start + PAGE); });
        prev.disabled = state.start <= 0;
        next.disabled = to >= state.total;
        add(pager, prev, el('span', 'h-muted', t('page_info', { from: from, to: to, total: state.total })), next);
    }

    // ---- one changeset ----------------------------------------------------------------
    function diffBlock(text) {
        var pre = el('pre', 'h-diff');
        String(text).split('\n').forEach(function (line) {
            var cls = line.charAt(0) === '-' ? 'h-del' : (line.charAt(0) === '+' ? 'h-ins' : (line.substr(0, 2) === '@@' ? 'h-hunk' : ''));
            add(pre, el('div', cls, line === '' ? ' ' : line));
        });
        return pre;
    }

    function fieldRow(name, change) {
        var row = el('div', 'h-field-row');
        add(row, el('div', 'h-fname', name));
        if (change && change.diff !== undefined) {
            add(row, diffBlock(change.diff));
        } else {
            add(row,
                add(el('div', 'h-old'), el('span', 'h-tag', t('was')), el('pre', '', clip(valueText(change ? change.old : null)))),
                add(el('div', 'h-new'), el('span', 'h-tag', t('now')), el('pre', '', clip(valueText(change ? change['new'] : null)))));
        }
        return row;
    }

    function objectBlock(o) {
        var box = el('div', 'h-obj');
        add(box, add(el('div', 'h-objhead'),
            el('span', 'h-op h-op-' + o.op, opLabel(o.op)), document.createTextNode(' '),
            el('span', 'h-kind', o.kind), document.createTextNode(' '),
            el('b', '', o.name)));
        var names = o.changed ? Object.keys(o.changed) : [];
        names.forEach(function (name) { add(box, fieldRow(name, o.changed[name])); });
        if (o.changed && !names.length) { add(box, el('div', 'h-muted', t('no_fields'))); }
        if (o.note) { add(box, el('div', 'h-note', o.note)); }
        return box;
    }

    var planBox = null;

    function closeDetail() {
        state.openId = null;
        clear(detail);
        Array.prototype.forEach.call(tableBox.querySelectorAll('tr.sel'), function (tr) { tr.className = ''; });
    }

    function openChangeset(id) {
        state.openId = id;
        Array.prototype.forEach.call(tableBox.querySelectorAll('tr[data-id]'), function (tr) {
            tr.className = +tr.getAttribute('data-id') === id ? 'sel' : '';
        });
        clear(detail);
        add(detail, el('p', 'h-muted', t('loading')));
        request('mgr/history/get', { id: id }, function (r) { drawDetail(r.object); }, function (r) {
            clear(detail);
            add(detail, el('p', 'h-error', r.message || t('load_failed')));
        });
    }

    function drawDetail(c) {
        clear(detail);
        planBox = el('div', 'h-plan');
        var head = add(el('div', 'h-dhead'),
            el('h3', '', t('detail_title', { n: c.changeset_id })),
            button(t('close'), 'h-right', closeDetail));
        add(detail, head,
            el('div', 'h-muted', t('detail_meta', { time: fmtTime(c.at), source: sourceLabel(c.source), author: c.author, tool: c.tool })),
            el('p', 'h-summary', c.summary));
        if (c.undoes && c.undoes.length) { add(detail, el('div', 'h-muted', t('undoes_label', { list: c.undoes.join(', ') }))); }
        (c.objects || []).forEach(function (o) { add(detail, objectBlock(o)); });

        var actions = el('div', 'h-actions');
        if (c.status === 'undone') {
            add(actions, el('span', 'h-muted', t('already_undone', { n: c.undone_by })));
        } else if (!c.reversible) {
            add(actions, el('div', 'h-note', t(c.not_reversible === 'no_changes' ? 'nr_no_changes' : 'nr_blocked')));
            (c.notes || []).forEach(function (n) { add(actions, el('div', 'h-muted', n)); });
        } else {
            add(actions, button(t('undo_btn'), 'h-primary', function () { startUndo({ changeset_id: c.changeset_id }, t('undo_plan_title', { n: c.changeset_id }), planBox); }));
        }
        add(detail, actions, planBox);
    }

    // ---- undo: plan, then confirm ----------------------------------------------------
    var PLAN_ACTION = { restore: 'plan_restore', recreate: 'plan_recreate', remove: 'plan_remove' };

    function startUndo(selector, title, box) {
        clear(box);
        add(box, el('div', 'h-muted', t('loading')));
        request('mgr/history/undo', selector, function (r) { drawPlan(r.object, selector, title, box); }, function (r) {
            clear(box);
            add(box, el('div', 'h-error', t('undo_failed', { message: r.message || '' })));
        });
    }

    function drawPlan(plan, selector, title, box) {
        clear(box);
        add(box, el('h4', '', title));
        if (!plan.changesets || !plan.changesets.length) {
            add(box, el('div', 'h-muted', t('plan_none')));
            return;
        }
        add(box, el('div', 'h-muted', t('plan_count', { n: plan.changesets.length })));
        var list = el('ul', 'h-planlist');
        (plan.objects || []).forEach(function (o) {
            var item = el('li', o.conflict ? 'h-conflict' : '');
            add(item, el('span', 'h-kind', o.kind), document.createTextNode(' '), el('b', '', o.name),
                document.createTextNode(' — ' + t(PLAN_ACTION[o.action] || 'plan_restore')));
            if (o.conflict) { add(item, el('div', 'h-reason', t('conflict_label', { reason: o.conflict }))); }
            list.appendChild(item);
        });
        add(box, list);

        var buttons = el('div', 'h-actions');
        function run(force) {
            var params = { confirm: 'true' };
            if (force) { params.force = 'true'; }
            Object.keys(selector).forEach(function (k) { params[k] = selector[k]; });
            Array.prototype.forEach.call(buttons.querySelectorAll('button'), function (b) { b.disabled = true; });
            request('mgr/history/undo', params, function (r) { undone(r.object, box); }, function (r) {
                clear(box);
                add(box, el('div', 'h-error', t('undo_failed', { message: r.message || '' })));
            });
        }
        if (plan.conflicts) {
            add(box, el('div', 'h-warn', t('force_warn')));
            add(buttons, button(t('force_btn'), 'h-danger', function () { run(true); }));
        } else {
            add(buttons, button(t('confirm_btn'), 'h-primary', function () { run(false); }));
        }
        add(buttons, button(t('cancel_btn'), '', function () { clear(box); }));
        add(box, buttons);
    }

    function undone(result, box) {
        clear(box);
        // The opened change is undone now; its own undo button must not be offered again.
        Array.prototype.forEach.call(detail.querySelectorAll('.h-actions > button'), function (b) { b.disabled = true; });
        add(box, el('div', 'h-ok', t('undo_done', { n: result.changeset_id })));
        // Show the new changeset at the top: the filters could hide it, so they are reset.
        inputs.source.value = '';
        inputs.kind.value = '';
        inputs.object.value = '';
        inputs.query.value = '';
        inputs.since.value = '';
        inputs.until.value = '';
        reload(0);
        loadCheckpoints();
    }

    // ---- checkpoints ------------------------------------------------------------------
    function loadCheckpoints() {
        request('mgr/history/checkpoints', {}, function (r) { drawCheckpoints((r.object && r.object.checkpoints) || []); }, function () {
            clear(side);
            add(side, el('h3', '', t('cp_title')), el('p', 'h-error', t('load_failed')));
        });
    }

    function drawCheckpoints(items) {
        clear(side);
        add(side, el('h3', '', t('cp_title')));
        if (!items.length) {
            add(side, el('p', 'h-muted', t('cp_empty')));
            return;
        }
        items.forEach(function (cp) {
            var box = el('div', 'h-cp');
            var plan = el('div', 'h-plan');
            add(box,
                el('b', '', cp.label),
                el('div', 'h-muted', fmtTime(cp.at) + ' · ' + cp.author),
                el('div', 'h-muted', t('cp_after', { n: cp.undoable_after })),
                button(t('cp_rollback'), '', function () {
                    startUndo({ to_checkpoint: cp.checkpoint_id }, t('cp_plan_title', { label: cp.label }), plan);
                }),
                plan);
            side.appendChild(box);
        });
    }

    reload(0);
    loadCheckpoints();
});
