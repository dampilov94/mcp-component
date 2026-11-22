/**
 * modxMCP - tokens screen (Components > modxMCP > Токены).
 *
 * Who uses which access: a table of the API tokens, creating one together with a ready client configuration,
 * changing and revoking. Plain ES5 and MODx.Ajax, no libraries.
 *
 * Every value from the site (labels, names, the token itself, the configuration text) is put into the page with
 * textContent only; innerHTML is never used with data. All texts come from the lexicon (ModxmcpTokens.l).
 */
Ext.onReady(function () {
    var root = document.getElementById('mcpt');
    if (!root || typeof MODx === 'undefined' || !MODx.Ajax) { return; }

    var cfg = (typeof ModxmcpTokens !== 'undefined') ? ModxmcpTokens : {};
    var L = cfg.l || {};
    var GROUPS = cfg.groups || [];
    var SCOPES = cfg.scopes || [];

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
        var b = el('button', 'k-btn' + (cls ? ' ' + cls : ''), label);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
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
    function groupLabel(key) {
        return L.hasOwnProperty('group_' + key) ? L['group_' + key] : key;
    }
    // "miniShop2 (товары / опции)" -> "miniShop2": the long label goes into a tooltip
    function groupShort(key) {
        return groupLabel(key).replace(/\s*\(.*$/, '');
    }
    function field(label, node) {
        return add(el('label', 'k-field'), el('span', 'k-flabel', label), node);
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
    function checkbox(checked) {
        var c = el('input');
        c.type = 'checkbox';
        c.checked = !!checked;
        return c;
    }
    function failText(r) { return r && r.message ? r.message : ''; }

    // ---- copying ----------------------------------------------------------------------
    // The clipboard API exists only on HTTPS and localhost; elsewhere the text is selected for Ctrl+C.
    function selectText(node) {
        var range = document.createRange();
        range.selectNodeContents(node);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function copyNode(node, statusEl) {
        function fallback() {
            selectText(node);
            var done = false;
            try { done = document.execCommand && document.execCommand('copy'); } catch (e) { done = false; }
            statusEl.textContent = done ? t('copied') : t('copy_failed');
        }
        if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
            navigator.clipboard.writeText(node.textContent).then(function () { statusEl.textContent = t('copied'); }, fallback);
        } else {
            fallback();
        }
    }
    function copyBlock(title, text, preClass) {
        var pre = el('pre', 'k-pre ' + (preClass || ''), text);
        var status = el('span', 'k-muted');
        var head = add(el('div', 'k-copyhead'), el('b', '', title),
            button(t('btn_copy'), '', function () { copyNode(pre, status); }), status);
        return add(el('div', 'k-copyblock'), head, pre);
    }

    // The ready snippets of a new token: a select "Клиент" picks the client, the box shows its text.
    function configBlock(rec) {
        var configs = rec.configs || [];
        if (!configs.length) { return copyBlock(t('result_config'), rec.config || '', ''); }
        var wrap = el('div', 'k-copyblock');
        var select = el('select');
        configs.forEach(function (item) {
            var option = el('option', '', item.id === 'node' ? t('client_node') : item.title);
            option.value = item.id;
            select.appendChild(option);
        });
        select.value = configs[0].id;
        var pre = el('pre', 'k-pre', '');
        var file = el('span', 'k-muted');
        var status = el('span', 'k-muted');
        function show() {
            var current = configs[0];
            configs.forEach(function (item) { if (item.id === select.value) { current = item; } });
            pre.textContent = current.text;
            file.textContent = t('result_config_file', { file: current.file });
            status.textContent = '';
        }
        select.addEventListener('change', show);
        show();
        add(wrap,
            add(el('div', 'k-copyhead'), el('b', '', t('f_client')), select,
                button(t('btn_copy'), '', function () { copyNode(pre, status); }), status),
            add(el('p', 'k-muted'), file),
            pre);
        return wrap;
    }

    // ---- layout -----------------------------------------------------------------------
    var statEl = el('span', 'k-stat');
    var createBtn = button(t('btn_create'), 'k-primary', function () { toggleCreate(); });
    add(root, add(el('div', 'k-bar'), el('h2', '', t('heading')), statEl, createBtn));

    var intro = el('div', 'k-intro');
    add(intro, el('p', 'k-muted', t('intro')));
    var scopeList = el('ul', 'k-scopes');
    SCOPES.forEach(function (s) {
        add(scopeList, add(el('li'), el('span', 'k-scope k-scope-' + s, t('scope_' + s)), document.createTextNode(' ' + t('scope_' + s + '_desc'))));
    });
    add(scopeList, add(el('li'), el('b', '', t('plan_only_title')), document.createTextNode(': ' + t('plan_only_desc'))));
    add(intro, el('h3', '', t('scopes_title')), scopeList);
    add(root, intro);

    var resultBox = el('div', 'k-result');
    var createBox = el('div', 'k-form');
    createBox.style.display = 'none';
    var tableBox = el('div', 'k-tablebox');
    var inactiveToggle = el('p', 'k-toggle');
    var inactiveBox = el('div', 'k-tablebox');
    inactiveBox.style.display = 'none';
    add(root, resultBox, createBox, tableBox, inactiveToggle, inactiveBox);

    // ---- the list ---------------------------------------------------------------------
    var state = { tokens: [], showInactive: false, editId: null, editRow: null };

    function reload() {
        statEl.textContent = t('loading');
        request('mgr/tokens/getlist', {}, function (r) {
            state.tokens = r.results || [];
            statEl.textContent = '';
            draw();
        }, function (r) {
            statEl.textContent = '';
            clear(tableBox);
            add(tableBox, el('p', 'k-error', t('load_failed') + ' ' + failText(r)));
        });
    }

    function scopeBadge(scope) {
        return el('span', 'k-scope k-scope-' + scope, t('scope_' + scope));
    }

    function deniedCell(groups) {
        var cell = el('td');
        if (!groups || !groups.length) { return add(cell, el('span', 'k-muted', t('none'))); }
        groups.forEach(function (g, i) {
            var span = el('span', 'k-group', groupShort(g));
            span.title = groupLabel(g);
            add(cell, i ? document.createTextNode(' ') : null, span);
        });
        return cell;
    }

    function statusCell(tok) {
        return add(el('td'), el('span', 'k-status k-status-' + tok.status, t('status_' + tok.status)));
    }

    function row(tok, active) {
        var tr = el('tr', active ? '' : 'k-inactive');
        tr.setAttribute('data-id', tok.id);
        var created = fmtTime(tok.created_at) + (tok.created_by_name ? ', ' + tok.created_by_name : '');
        add(tr,
            el('td', 'k-label', tok.label !== '' ? tok.label : t('no_label')),
            add(el('td'), el('code', '', tok.prefix + '…')),
            add(el('td'), scopeBadge(tok.scope)),
            el('td', '', tok.plan_only ? t('yes') : t('none')),
            deniedCell(tok.denied_groups),
            el('td', 'k-nowrap', created),
            el('td', 'k-nowrap', tok.last_used_at ? fmtTime(tok.last_used_at) : t('unused')),
            el('td', 'k-nowrap', tok.expires_at ? fmtTime(tok.expires_at) : t('never')),
            el('td', 'k-num', tok.changesets),
            statusCell(tok));
        var actions = el('td', 'k-nowrap');
        if (active) {
            add(actions,
                button(t('btn_edit'), '', function () { toggleEdit(tok, tr); }),
                document.createTextNode(' '),
                button(t('btn_revoke'), 'k-danger', function () { revoke(tok); }));
        }
        return add(tr, actions);
    }

    function table(tokens, active) {
        var tbl = el('table', 'k-table');
        var head = el('tr');
        ['col_label', 'col_prefix', 'col_scope', 'col_plan', 'col_denied', 'col_created', 'col_last_used', 'col_expires', 'col_changesets', 'col_status'].forEach(function (k) {
            add(head, el('th', '', t(k)));
        });
        add(head, el('th'));
        var body = el('tbody');
        tokens.forEach(function (tok) { add(body, row(tok, active)); });
        return add(tbl, add(el('thead'), head), body);
    }

    function draw() {
        var active = state.tokens.filter(function (x) { return x.status === 'active'; });
        var inactive = state.tokens.filter(function (x) { return x.status !== 'active'; });
        state.editRow = null;
        clear(tableBox);
        clear(inactiveBox);
        clear(inactiveToggle);
        if (!state.tokens.length) {
            add(tableBox, el('p', 'k-muted k-pad', t('empty')));
            return;
        }
        if (active.length) { add(tableBox, table(active, true)); }
        if (inactive.length) {
            var toggle = el('a', 'k-link', t(state.showInactive ? 'hide_inactive' : 'show_inactive', { n: inactive.length }));
            toggle.href = '#';
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                state.showInactive = !state.showInactive;
                draw();
            });
            add(inactiveToggle, toggle);
            add(inactiveBox, table(inactive, false));
        }
        inactiveBox.style.display = state.showInactive ? '' : 'none';
        if (state.editId !== null) {
            var open = active.filter(function (x) { return x.id === state.editId; })[0];
            var tr = tableBox.querySelector('tr[data-id="' + state.editId + '"]');
            if (open && tr) { toggleEdit(open, tr); } else { state.editId = null; }
        }
    }

    // ---- revoke -----------------------------------------------------------------------
    function revoke(tok) {
        if (!confirm(t('confirm_revoke', { label: tok.label !== '' ? tok.label : t('no_label') }))) { return; }
        request('mgr/tokens/revoke', { id: tok.id }, function () {
            if (state.editId === tok.id) { state.editId = null; }
            reload();
        }, function (r) { alert(t('revoke_failed', { message: failText(r) })); });
    }

    // ---- shared pieces of the forms ---------------------------------------------------
    function expiresSelect(withKeep) {
        var options = [];
        if (withKeep) { options.push(['', t('expires_keep')]); }
        options.push(['0', t('expires_never')], ['30', t('expires_30')], ['90', t('expires_90')], ['365', t('expires_365')]);
        return select(options);
    }
    function groupBoxes(denied, open) {
        var boxes = {};
        var list = el('div', 'k-groups');
        GROUPS.forEach(function (g) {
            var c = checkbox(denied.indexOf(g) !== -1);
            boxes[g] = c;
            add(list, add(el('label', 'k-check'), c, document.createTextNode(' ' + groupLabel(g))));
        });
        var details = el('details', 'k-details');
        if (open) { details.open = true; }
        add(details, el('summary', '', t('groups_toggle')), el('p', 'k-muted', t('groups_hint')), list);
        return {
            node: details,
            value: function () { return GROUPS.filter(function (g) { return boxes[g].checked; }).join(','); }
        };
    }

    // ---- create -----------------------------------------------------------------------
    function toggleCreate() {
        if (createBox.style.display === 'none') {
            drawCreate();
            createBox.style.display = '';
        } else {
            createBox.style.display = 'none';
        }
    }

    function drawCreate() {
        clear(createBox);
        var label = el('input');
        label.type = 'text';
        label.maxLength = 190;
        label.placeholder = t('label_ph');
        var scope = select(SCOPES.map(function (s) { return [s, t('scope_' + s) + ' — ' + t('scope_' + s + '_desc')]; }));
        scope.value = SCOPES.indexOf('develop') !== -1 ? 'develop' : scope.value;
        var plan = checkbox(false);
        var expires = expiresSelect(false);
        var groups = groupBoxes([], false);
        var err = el('div', 'k-error');
        var submit = button(t('btn_create'), 'k-primary', function () {
            if (label.value.replace(/^\s+|\s+$/g, '') === '') { err.textContent = t('label_required'); return; }
            err.textContent = '';
            submit.disabled = true;
            request('mgr/tokens/create', {
                label: label.value,
                scope: scope.value,
                plan_only: plan.checked ? '1' : '0',
                expires_days: expires.value,
                denied_groups: groups.value()
            }, function (r) {
                submit.disabled = false;
                createBox.style.display = 'none';
                showResult(r.object || {});
                reload();
            }, function (r) {
                submit.disabled = false;
                err.textContent = t('create_failed', { message: failText(r) });
            });
        });
        add(createBox,
            el('h3', '', t('create_title')),
            field(t('f_label'), label),
            field(t('f_scope'), scope),
            add(el('label', 'k-check'), plan, document.createTextNode(' ' + t('f_plan'))),
            field(t('f_expires'), expires),
            groups.node,
            err,
            add(el('div', 'k-actions'), submit, button(t('btn_cancel'), '', function () { createBox.style.display = 'none'; })));
    }

    // The new token and its configuration stay on the page until closed or reloaded: they are shown only once.
    function showResult(rec) {
        clear(resultBox);
        add(resultBox,
            add(el('div', 'k-resulthead'),
                el('h3', '', t('result_title', { label: rec.label || '' })),
                button(t('btn_close'), '', function () { clear(resultBox); })),
            el('p', 'k-warn', t('result_once')),
            copyBlock(t('result_token'), rec.token || '', 'k-token'),
            el('p', 'k-muted', t('result_config_hint')),
            el('h4', '', t('result_config')),
            configBlock(rec));
        if (resultBox.scrollIntoView) { resultBox.scrollIntoView(); }
    }

    // ---- edit -------------------------------------------------------------------------
    function toggleEdit(tok, tr) {
        if (state.editRow && state.editRow.parentNode) { state.editRow.parentNode.removeChild(state.editRow); }
        var same = state.editId === tok.id && state.editRow;
        state.editRow = null;
        if (same) { state.editId = null; return; }
        state.editId = tok.id;

        var label = el('input');
        label.type = 'text';
        label.maxLength = 190;
        label.value = tok.label;
        var plan = checkbox(tok.plan_only);
        var expires = expiresSelect(true);
        var groups = groupBoxes(tok.denied_groups || [], (tok.denied_groups || []).length > 0);
        var err = el('div', 'k-error');
        var save = button(t('btn_save'), 'k-primary', function () {
            if (label.value.replace(/^\s+|\s+$/g, '') === '') { err.textContent = t('label_required'); return; }
            err.textContent = '';
            save.disabled = true;
            request('mgr/tokens/update', {
                id: tok.id,
                label: label.value,
                plan_only: plan.checked ? '1' : '0',
                expires_days: expires.value,
                denied_groups: groups.value()
            }, function () {
                state.editId = null;
                reload();
            }, function (r) {
                save.disabled = false;
                err.textContent = t('save_failed', { message: failText(r) });
            });
        });
        var form = add(el('div', 'k-form k-editform'),
            el('h3', '', t('edit_title', { label: tok.label !== '' ? tok.label : t('no_label') })),
            field(t('f_label'), label),
            add(el('label', 'k-check'), plan, document.createTextNode(' ' + t('f_plan'))),
            field(t('f_expires'), expires),
            groups.node,
            err,
            add(el('div', 'k-actions'), save, button(t('btn_cancel'), '', function () { toggleEdit(tok, tr); })));
        var cell = el('td');
        cell.colSpan = 11;
        var editRow = add(el('tr', 'k-editrow'), add(cell, form));
        tr.parentNode.insertBefore(editRow, tr.nextSibling);
        state.editRow = editRow;
    }

    reload();
});
