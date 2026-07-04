/* Ostrakon — participants table. Vanilla JS + Bulma markup. */
(function () {
    'use strict';

    var cfg = window.OSTRAKON;
    if (!cfg) { return; }

    var i18n = cfg.i18n || {};
    var full = cfg.mode === 'full';
    var colCount = full ? 6 : 4;

    // Default sort (Ctrl+click returns to it).
    function defaultSorts() { return [{ col: 'joined_at', dir: 'desc' }]; }

    var state = { q: '', sorts: defaultSorts(), page: 1 };
    var viewerIsOwner = false; // set from each API response; gates the manager grant/revoke buttons

    var body   = document.getElementById('p-body');
    var pager  = document.getElementById('p-pager');
    var search = document.getElementById('p-search');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function rowMsg(text) {
        return '<tr><td colspan="' + colCount + '" class="has-text-centered has-text-grey py-5">' + esc(text) + '</td></tr>';
    }

    function userCell(r) {
        var main = r.username ? '@' + esc(r.username) : esc(i18n.noUsername || '');
        var badge = '';
        if (Number(r.is_owner) === 1) {
            badge = ' <span class="tag is-warning is-light">' + esc(i18n.owner || 'owner') + '</span>';
        } else if (Number(r.is_admin) === 1) {
            badge = ' <span class="tag is-info is-light">' + esc(i18n.admin || 'admin') + '</span>';
        }
        var title = (r.admin_title && r.admin_title !== '')
            ? ' <span class="tag is-light">«' + esc(r.admin_title) + '»</span>'
            : '';
        var mgr = (Number(r.is_admin) === 1 && Number(r.can_manage) === 1)
            ? ' <span class="tag is-success is-light">' + esc(i18n.managerBadge || 'manager') + '</span>'
            : '';
        return '<strong>' + main + '</strong>' + badge + mgr + title + '<br><span class="is-size-7 has-text-grey">id ' + esc(r.user_id) + '</span>';
    }

    function statusCell(r) {
        if (r.banned_at) { return '<span class="tag is-danger is-light is-medium">' + esc(i18n.banned) + '</span>'; }
        if (Number(r.is_protected) === 1) { return '<span class="tag is-success is-light is-medium">' + esc(i18n.protected) + '</span>'; }
        return '<span class="tag is-light is-medium">' + esc(i18n.active) + '</span>';
    }

    function actionsCell(r) {
        var html = r.banned_at
            ? btn('unban', i18n.unban, r.user_id, 'is-danger is-outlined')
            : (Number(r.is_protected) === 1
                ? btn('unprotect', i18n.unprotect, r.user_id, 'is-link is-outlined')
                : btn('protect', i18n.protect, r.user_id, 'is-link'));
        // Manager grant/revoke — owner only, for admin (non-owner) targets.
        if (viewerIsOwner && Number(r.is_admin) === 1 && Number(r.is_owner) !== 1) {
            html += Number(r.can_manage) === 1
                ? ' ' + btn('revoke_manage', i18n.revokeManage, r.user_id, 'is-warning is-outlined')
                : ' ' + btn('grant_manage', i18n.grantManage, r.user_id, 'is-success is-outlined');
        }
        // Manual elder appointment — full mode, for ordinary (non-admin) non-elder non-banned
        // members. Admins/owners don't need it (they can't be voted against anyway).
        if (full && !r.banned_at && Number(r.is_admin) !== 1) {
            var thr = Number(cfg.elderThreshold) || 0;
            var isElder = Number(r.is_elder) === 1 || (thr > 0 && Number(r.score) >= thr);
            if (!isElder) {
                html += ' ' + btn('make_elder', i18n.makeElder, r.user_id, 'is-warning is-outlined');
            }
        }
        return html;
    }

    function elderCell(r) {
        var thr = Number(cfg.elderThreshold) || 0;
        if (thr <= 0) { return '<span class="has-text-grey">—</span>'; }
        var pct = Math.min(100, Math.round(Number(r.score) / thr * 100));
        if (pct >= 100) {
            var label = (cfg.elderTitle && cfg.elderTitle !== '') ? esc(cfg.elderTitle) : '100%';
            return '<span class="tag is-success">' + label + '</span>';
        }
        if (pct >= 75)  { return '<span class="tag is-success is-light">' + pct + '%</span>'; }
        return '<span class="has-text-grey">' + pct + '%</span>';
    }

    function btn(action, label, userId, cls) {
        return '<button class="button ' + cls + '" data-action="' + action + '" data-user="' + esc(userId) + '">' + esc(label) + '</button>';
    }

    function renderRows(rows) {
        if (!rows.length) { body.innerHTML = rowMsg(i18n.empty); return; }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            html += '<tr>';
            html += '<td>' + userCell(r) + '</td>';
            html += '<td>' + esc(String(r.joined_at || '').slice(0, 16)) + '</td>';
            if (full) {
                html += '<td>' + elderCell(r) + '</td>';
                html += '<td>' + esc(r.msg_count) + '</td>';
            }
            html += '<td>' + statusCell(r) + '</td>';
            html += '<td>' + actionsCell(r) + '</td>';
            html += '</tr>';
        }
        body.innerHTML = html;
    }

    function renderPager(data) {
        if (data.pages <= 1) { pager.innerHTML = ''; return; }
        var label = (i18n.pageOf || '{page}/{pages}')
            .replace('{page}', data.page).replace('{pages}', data.pages);
        var prevDis = data.page <= 1 ? ' disabled' : '';
        var nextDis = data.page >= data.pages ? ' disabled' : '';
        pager.innerHTML =
            '<a class="pagination-previous" id="p-prev"' + prevDis + '>' + esc(i18n.prev) + '</a>' +
            '<a class="pagination-next" id="p-next"' + nextDis + '>' + esc(i18n.next) + '</a>' +
            '<ul class="pagination-list"><li><span class="pagination-link is-current">' + esc(label) + '</span></li></ul>';
        var p = document.getElementById('p-prev');
        var n = document.getElementById('p-next');
        if (p) { p.onclick = function () { if (state.page > 1) { state.page--; load(); } }; }
        if (n) { n.onclick = function () { if (state.page < data.pages) { state.page++; load(); } }; }
    }

    function sortParam() {
        return state.sorts.map(function (s) { return s.col + ':' + s.dir; }).join(',');
    }

    function load() {
        body.innerHTML = rowMsg(i18n.loading);
        var url = cfg.apiUrl + '?page=' + state.page +
            '&sort=' + encodeURIComponent(sortParam()) +
            '&q=' + encodeURIComponent(state.q);
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) { body.innerHTML = rowMsg(i18n.failed); return; }
                viewerIsOwner = !!data.viewerIsOwner;
                renderRows(data.rows || []);
                renderPager(data);
            })
            .catch(function () { body.innerHTML = rowMsg(i18n.failed); });
    }

    function doAction(action, userId, el) {
        if (action === 'unban' && i18n.confirmUnban && !window.confirm(i18n.confirmUnban)) { return; }
        if (action === 'make_elder' && i18n.confirmMakeElder && !window.confirm(i18n.confirmMakeElder)) { return; }
        if (el) { el.classList.add('is-loading'); el.disabled = true; }
        var data = new URLSearchParams();
        data.set('csrf', cfg.csrf);
        data.set('user_id', userId);
        data.set('action', action);
        fetch(cfg.actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (resp) {
                if (!resp || !resp.ok) { alert(i18n.failed); if (el) { el.classList.remove('is-loading'); el.disabled = false; } return; }
                load();
            })
            .catch(function () { alert(i18n.failed); if (el) { el.classList.remove('is-loading'); el.disabled = false; } });
    }

    // actions (event delegation on tbody)
    body.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.button[data-action]') : null;
        if (!b) { return; }
        doAction(b.getAttribute('data-action'), b.getAttribute('data-user'), b);
    });

    // ---- Sorting ----

    var ths = document.querySelectorAll('th.sortable');

    function findCol(col) {
        for (var i = 0; i < state.sorts.length; i++) {
            if (state.sorts[i].col === col) { return i; }
        }
        return -1;
    }

    function onHeader(e) {
        var col = this.getAttribute('data-sort');
        if (e.ctrlKey || e.metaKey) {
            // reset to the default sort
            state.sorts = defaultSorts();
        } else if (e.shiftKey) {
            // add a level (or toggle the direction of an existing one)
            var i = findCol(col);
            if (i >= 0) { state.sorts[i].dir = state.sorts[i].dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sorts.push({ col: col, dir: 'asc' }); }
        } else {
            // single-field sort; a repeat click toggles the direction
            if (state.sorts.length === 1 && state.sorts[0].col === col) {
                state.sorts[0].dir = state.sorts[0].dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sorts = [{ col: col, dir: 'asc' }];
            }
        }
        state.page = 1;
        markSort();
        load();
    }

    function ind(th) {
        var s = th.querySelector('.sort-ind');
        if (!s) { s = document.createElement('span'); s.className = 'sort-ind'; th.appendChild(s); }
        return s;
    }

    function markSort() {
        var multi = state.sorts.length > 1;
        for (var j = 0; j < ths.length; j++) {
            var th = ths[j];
            var idx = findCol(th.getAttribute('data-sort'));
            var s = ind(th);
            if (idx < 0) { s.innerHTML = ''; continue; }
            var arrow = state.sorts[idx].dir === 'asc' ? '↑' : '↓';
            s.innerHTML = ' ' + arrow + (multi ? '<sup>' + (idx + 1) + '</sup>' : '');
        }
    }

    for (var t = 0; t < ths.length; t++) {
        ths[t].addEventListener('click', onHeader);
    }

    // debounced search
    var tmr = null;
    if (search) {
        search.addEventListener('input', function () {
            clearTimeout(tmr);
            var v = this.value;
            tmr = setTimeout(function () { state.q = v.trim(); state.page = 1; load(); }, 300);
        });
    }

    markSort();
    load();
})();
