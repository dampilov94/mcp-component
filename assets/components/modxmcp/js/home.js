/**
 * modxMCP - the component's main page (Components > modxMCP): copying the endpoint and saving the capability toggles.
 * The rest of the page is rendered on the server. Plain ES5 and MODx.Ajax; texts come from the lexicon (Modxmcp.l),
 * values from the site are put into the page with textContent only.
 */
Ext.onReady(function () {
    if (typeof MODx === 'undefined' || !MODx.Ajax) {
        return;
    }
    var cfg = (typeof Modxmcp !== 'undefined') ? Modxmcp : {};
    var L = cfg.l || {};

    // ---- copy the endpoint --------------------------------------------------------------
    var copyBtn = document.getElementById('modxmcp-copy-endpoint');
    var endpoint = document.getElementById('modxmcp-endpoint');
    var copyStatus = document.getElementById('modxmcp-copy-status');
    function selectText(node) {
        var range = document.createRange();
        range.selectNodeContents(node);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    if (copyBtn && endpoint) {
        copyBtn.addEventListener('click', function () {
            var text = endpoint.textContent;
            function fallback() {
                // Plain-HTTP sites have no clipboard API: select the text so that Ctrl+C works.
                selectText(endpoint);
                var done = false;
                try { done = document.execCommand && document.execCommand('copy'); } catch (e) { done = false; }
                if (copyStatus) { copyStatus.textContent = done ? (L.copied || '') : (L.copy_failed || ''); }
            }
            if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    if (copyStatus) { copyStatus.textContent = L.copied || ''; }
                }, fallback);
            } else {
                fallback();
            }
        });
    }

    // ---- capability toggles -------------------------------------------------------------
    var saveBtn = document.getElementById('modxmcp-savegroups');
    if (saveBtn) {
        var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.modxmcp-grp')); };
        var stateKey = function () { return boxes().map(function (b) { return (b.checked ? '1' : '0') + b.getAttribute('data-group'); }).join('|'); };
        var savedState = stateKey();
        var status = document.getElementById('modxmcp-groups-status');

        // Save button is active only while there are unsaved changes.
        function refreshDirty() {
            var dirty = stateKey() !== savedState;
            saveBtn.disabled = !dirty;
            if (status && !dirty) { status.textContent = ''; }
        }
        boxes().forEach(function (b) { b.addEventListener('change', refreshDirty); });

        saveBtn.addEventListener('click', function () {
            var disabled = boxes().filter(function (b) { return !b.checked; }).map(function (b) { return b.getAttribute('data-group'); });
            saveBtn.disabled = true;
            if (status) { status.textContent = L.features_saving || ''; }
            MODx.Ajax.request({
                url: cfg.connector_url,
                params: { action: 'mgr/savegroups', disabled: disabled.join(',') },
                listeners: {
                    success: {
                        fn: function () {
                            savedState = stateKey();
                            saveBtn.disabled = true;
                            if (status) { status.textContent = L.features_saved || ''; }
                        }
                    },
                    failure: {
                        fn: function () {
                            saveBtn.disabled = false;
                            if (status) { status.textContent = L.features_failed || ''; }
                        }
                    }
                }
            });
        });
    }

    // ---- drafts of pages: list, plan, confirmation ----------------------------------------
    // Every value from the site (titles, names, field values, diffs) goes into the page with textContent only.
    var draftsRoot = document.getElementById('modxmcp-drafts');
    var draftsBody = document.getElementById('modxmcp-drafts-body');
    var DL = cfg.dl || {};
    if (draftsRoot && draftsBody) {
        var dt = function (key, vars) {
            var text = DL.hasOwnProperty(key) ? DL[key] : key;
            if (vars) { Object.keys(vars).forEach(function (name) { text = text.split('{' + name + '}').join(String(vars[name])); }); }
            return text;
        };
        var el = function (tag, cls, text) {
            var node = document.createElement(tag);
            if (cls) { node.className = cls; }
            if (text !== undefined && text !== null) { node.textContent = String(text); }
            return node;
        };
        var add = function (parent) {
            for (var i = 1; i < arguments.length; i++) { if (arguments[i]) { parent.appendChild(arguments[i]); } }
            return parent;
        };
        var clear = function (node) { while (node.firstChild) { node.removeChild(node.firstChild); } };
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var fmtTime = function (unix) {
            var d = new Date(unix * 1000);
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        };
        var button = function (label, handler) {
            var b = el('button', 'o-btn', label);
            b.type = 'button';
            b.addEventListener('click', handler);
            return b;
        };
        var anchor = function (label, href, newTab) {
            var a = el('a', 'o-link', label);
            a.href = href;
            if (newTab) { a.target = '_blank'; a.rel = 'noopener'; }
            return a;
        };
        var shown = function (value) {
            if (value === null || value === undefined || value === '') { return dt('plan_empty'); }
            if (typeof value === 'string') { return value; }
            try { return JSON.stringify(value); } catch (e) { return String(value); }
        };
        var call = function (action, params, onOk, onFail) {
            params.action = action;
            MODx.Ajax.request({
                url: cfg.connector_url,
                method: 'POST',
                params: params,
                listeners: {
                    success: { fn: function (r) { onOk(r.object || {}); } },
                    failure: { fn: function (r) { onFail(r || {}); } }
                }
            });
        };
        var failed = function (box, r) {
            add(box, el('div', 'd-error', dt('failed', { message: (r && r.message) || '' })));
        };

        var drawPlan = function (draft, kind, plan, box, done) {
            clear(box);
            var panel = el('div', 'd-plan');
            if (kind === 'discard') {
                add(panel, el('h4', '', dt('plan_discard', { title: draft.original_title })));
            } else {
                add(panel, el('h4', '', dt('plan_apply', { title: draft.original_title })));
                var fields = Object.keys(plan.changes || {});
                var tvs = Object.keys(plan.tvs || {});
                var prices = Object.keys(plan.prices || {});
                if (!fields.length && !tvs.length && !prices.length) { add(panel, el('div', 'o-muted', dt('plan_no_changes'))); }
                if (fields.length) {
                    add(panel, el('h4', '', dt('plan_fields')));
                    fields.forEach(function (name) {
                        var change = plan.changes[name];
                        var row = el('div', 'd-change');
                        add(row, el('b', '', name));
                        if (change.diff !== undefined) {
                            add(row, el('div', 'd-diff', change.diff));
                        } else {
                            add(row, el('div', 'd-old', dt('plan_old') + ': ' + shown(change.old)), el('div', 'd-new', dt('plan_new') + ': ' + shown(change['new'])));
                        }
                        add(panel, row);
                    });
                }
                if (tvs.length) {
                    add(panel, el('h4', '', dt('plan_tvs')));
                    tvs.forEach(function (name) {
                        var change = plan.tvs[name];
                        var row = el('div', 'd-change');
                        add(row, el('b', '', name), el('div', 'd-old', dt('plan_old') + ': ' + shown(change.old)), el('div', 'd-new', dt('plan_new') + ': ' + shown(change['new'])));
                        add(panel, row);
                    });
                }
                if (prices.length) {
                    add(panel, el('h4', '', dt('plan_prices')));
                    prices.forEach(function (name) {
                        var change = plan.prices[name];
                        var row = el('div', 'd-change');
                        add(row, el('b', '', dt('price_' + name)), el('div', 'd-old', dt('plan_old') + ': ' + shown(change.old)),
                            el('div', 'd-new', dt('plan_new') + ': ' + shown(change['new'])));
                        add(panel, row);
                    });
                }
                if (plan.original_changed) {
                    add(panel, el('div', 'd-warn', dt('changed_warning', { fields: (plan.original_changed_fields || []).join(', ') })));
                }
                if (plan.open_in_manager) {
                    add(panel, el('div', 'd-warn', dt('open_warning', { user: plan.open_in_manager.username })));
                }
            }
            var buttons = el('div', 'd-actions');
            var run = function (force) {
                Array.prototype.forEach.call(buttons.querySelectorAll('button'), function (b) { b.disabled = true; });
                var params = { id: draft.draft_id, confirm: 'true' };
                if (force) { params.force = 'true'; }
                call(kind === 'discard' ? 'mgr/drafts/discard' : 'mgr/drafts/apply', params, function () { done(kind); }, function (r) {
                    Array.prototype.forEach.call(buttons.querySelectorAll('button'), function (b) { b.disabled = false; });
                    failed(panel, r);
                });
            };
            var needsForce = kind === 'apply' && (plan.original_changed || plan.open_in_manager);
            add(buttons, button(needsForce ? dt('force') : dt('confirm'), function () { run(needsForce); }));
            add(buttons, button(dt('cancel'), function () { clear(box); }));
            add(panel, buttons);
            add(box, panel);
        };

        var drawDrafts = function (drafts, message) {
            clear(draftsBody);
            draftsRoot.style.display = drafts.length || message ? '' : 'none';
            if (message) { add(draftsBody, el('div', 'd-ok', message)); }
            if (!drafts.length) { return; }
            add(draftsBody, el('p', 'd-intro', dt('intro')));
            var table = el('table', 'd-table');
            var head = el('tr');
            add(head, el('th', '', dt('col_page')), el('th', '', dt('col_created')), el('th', '', dt('col_actions')));
            add(table, head);
            drafts.forEach(function (draft) {
                var row = el('tr');
                var page = el('td');
                add(page, anchor(draft.original_title, draft.edit_url, false), document.createTextNode(' '), anchor('✎', draft.edit_draft_url, false));
                if (draft.original_changed) { add(page, el('div', 'd-warn', dt('changed_warning', { fields: (draft.original_changed_fields || []).join(', ') }))); }
                var created = el('td', '', fmtTime(draft.created_at) + ' · ' + dt('by') + ' ' + draft.author);
                var actions = el('td', 'd-actions');
                var box = el('div');
                var show = function (kind) {
                    clear(box);
                    add(box, el('div', 'o-muted', dt('plan_loading')));
                    call(kind === 'discard' ? 'mgr/drafts/discard' : 'mgr/drafts/apply', { id: draft.draft_id }, function (plan) {
                        drawPlan(draft, kind, plan, box, finished);
                    }, function (r) { clear(box); failed(box, r); });
                };
                if (draft.preview_url) { add(actions, anchor(dt('preview'), draft.preview_url, true)); }
                if (cfg.share_preview) {
                    var share = button(dt('share'), function () {
                        share.disabled = true;
                        clear(box);
                        add(box, el('div', 'o-muted', dt('share_loading')));
                        call('mgr/drafts/share', { id: draft.draft_id }, function (result) {
                            share.disabled = false;
                            clear(box);
                            var label = el('label', 'd-share', dt('share_link'));
                            var input = el('input');
                            input.type = 'text';
                            input.readOnly = true;
                            input.value = result.url || '';
                            input.addEventListener('focus', function () { input.select(); });
                            add(box, add(label, input));
                            input.focus();
                        }, function (r) { share.disabled = false; clear(box); failed(box, r); });
                    });
                    add(actions, share);
                }
                add(actions, button(dt('apply'), function () { show('apply'); }), button(dt('discard'), function () { show('discard'); }), box);
                add(row, page, created, actions);
                add(table, row);
            });
            add(draftsBody, table);
        };

        var finished = function (kind) {
            var message = dt(kind === 'discard' ? 'discarded' : 'applied');
            call('mgr/drafts/getlist', {}, function (r) { drawDrafts(r.drafts || [], message); }, function (r) {
                drawDrafts([], message);
                failed(draftsBody, r);
            });
        };

        drawDrafts(cfg.drafts || [], '');
    }
});
