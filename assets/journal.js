/* Ostrakon — vote journal (read-only). Vanilla JS + Bulma markup. */
(function () {
    'use strict';

    var cfg = window.OSTRAKON;
    if (!cfg) { return; }

    var i18n = cfg.i18n || {};
    var statusLabels = i18n.status || {};
    var colCount = 8;

    function defaultSorts() { return [{ col: 'started_at', dir: 'desc' }]; }
    var state = { q: '', status: '', sorts: defaultSorts(), page: 1 };

    var body    = document.getElementById('j-body');
    var pager   = document.getElementById('j-pager');
    var search  = document.getElementById('j-search');
    var status  = document.getElementById('j-status');
    var sortSel = document.getElementById('j-sortsel');

    // Column names, read before the sort arrows are added: on phones each cell shows its
    // column name as a label (the table turns into cards, see .cards-mobile in admin.css).
    var labels = [];
    var heads = document.querySelectorAll('thead th');
    for (var h = 0; h < heads.length; h++) { labels.push(heads[h].textContent.trim()); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function rowMsg(text) {
        return '<tr><td colspan="' + colCount + '" class="has-text-centered has-text-grey py-5">' + esc(text) + '</td></tr>';
    }

    function nameCell(username, id) {
        var main = username ? '@' + esc(username) : esc(i18n.noUsername || '');
        return '<strong>' + main + '</strong><br><span class="is-size-7 has-text-grey">id ' + esc(id) + '</span>';
    }

    function statusCell(st) {
        var cls = 'is-light';
        if (st === 'banned') { cls = 'is-danger is-light'; }
        else if (st === 'active') { cls = 'is-warning is-light'; }
        var label = statusLabels[st] || st;
        return '<span class="tag is-medium ' + cls + '">' + esc(label) + '</span>';
    }

    function num(x) { var n = Number(x); return isNaN(n) ? esc(x) : String(n); }
    function dt(x) { return x ? esc(String(x).slice(0, 16)) : esc(i18n.dash || '—'); }

    function actionsCell(r) {
        // Show unban only if the target is CURRENTLY banned (not just banned at some point).
        if (r.target_banned) {
            return '<button class="button is-danger is-outlined" data-action="unban" data-user="' + esc(r.target_id) + '">' + esc(i18n.unban) + '</button>';
        }
        return '';
    }

    // A data cell labelled with its column name (shown only in the phone card layout).
    function cell(col, content) {
        return '<td data-label="' + esc(labels[col] || '') + '">' + content + '</td>';
    }

    function renderRows(rows) {
        if (!rows.length) { body.innerHTML = rowMsg(i18n.empty); return; }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            html += '<tr>';
            html += '<td>' + nameCell(r.target_username, r.target_id) + '</td>'; // the card's heading — no label
            html += cell(1, nameCell(r.initiator_username, r.initiator_id));
            html += cell(2, statusCell(r.status));
            html += cell(3, num(r.for_sum));
            html += cell(4, num(r.against_sum));
            html += cell(5, dt(r.started_at));
            html += cell(6, dt(r.finished_at));
            html += '<td class="cell-actions">' + actionsCell(r) + '</td>';
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
            '<a class="pagination-previous" id="j-prev"' + prevDis + '>' + esc(i18n.prev) + '</a>' +
            '<a class="pagination-next" id="j-next"' + nextDis + '>' + esc(i18n.next) + '</a>' +
            '<ul class="pagination-list"><li><span class="pagination-link is-current">' + esc(label) + '</span></li></ul>';
        var p = document.getElementById('j-prev');
        var n = document.getElementById('j-next');
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
            '&status=' + encodeURIComponent(state.status) +
            '&q=' + encodeURIComponent(state.q);
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) { body.innerHTML = rowMsg(i18n.failed); return; }
                renderRows(data.rows || []);
                renderPager(data);
            })
            .catch(function () { body.innerHTML = rowMsg(i18n.failed); });
    }

    // ---- "Unban" action (via the participants action endpoint) ----
    function doAction(action, userId, el) {
        if (action === 'unban' && i18n.confirmUnban && !window.confirm(i18n.confirmUnban)) { return; }
        if (!cfg.actionUrl) { return; }
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

    body.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.button[data-action]') : null;
        if (!b) { return; }
        doAction(b.getAttribute('data-action'), b.getAttribute('data-user'), b);
    });

    // ---- Sorting (same as the participants table) ----
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
            state.sorts = defaultSorts();
        } else if (e.shiftKey) {
            var i = findCol(col);
            if (i >= 0) { state.sorts[i].dir = state.sorts[i].dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sorts.push({ col: col, dir: 'asc' }); }
        } else {
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
        // The phone select shows the first sort level.
        if (sortSel) { sortSel.value = state.sorts[0].col + ':' + state.sorts[0].dir; }
    }

    for (var t = 0; t < ths.length; t++) {
        ths[t].addEventListener('click', onHeader);
    }

    // Phone sort select: every sortable column in both directions (single-level sort).
    function fillSortSelect() {
        if (!sortSel) { return; }
        var opts = '';
        for (var j = 0; j < ths.length; j++) {
            var col  = ths[j].getAttribute('data-sort');
            var name = esc(ths[j].textContent.trim());
            opts += '<option value="' + col + ':desc">' + name + ' ↓</option>';
            opts += '<option value="' + col + ':asc">' + name + ' ↑</option>';
        }
        sortSel.innerHTML = opts;
        sortSel.addEventListener('change', function () {
            var p = this.value.split(':');
            state.sorts = [{ col: p[0], dir: p[1] }];
            state.page = 1;
            markSort();
            load();
        });
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

    // status filter
    if (status) {
        status.addEventListener('change', function () {
            state.status = this.value;
            state.page = 1;
            load();
        });
    }

    fillSortSelect(); // before markSort: the headers have no arrows yet
    markSort();
    load();
})();
