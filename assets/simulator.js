/* Ostrakon — elder simulator. Vanilla JS, computed in the browser. */
(function () {
    'use strict';

    var cfg = window.OSTRAKON_SIM;
    if (!cfg || !cfg.users) { return; }

    var i18n  = cfg.i18n || {};
    var users = cfg.users.slice(); // already sorted by rate desc
    var N = users.length;

    var elShare = document.getElementById('s-share');
    var elHor   = document.getElementById('s-horizon');
    var elHalf  = document.getElementById('s-half');
    var elThr   = document.getElementById('s-threshold');
    var nShare  = document.getElementById('s-share-n');
    var nHor    = document.getElementById('s-horizon-n');
    var nHalf   = document.getElementById('s-half-n');
    var elCount = document.getElementById('s-count');
    var body    = document.getElementById('s-body');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function lambda() {
        var h = Math.max(1, Number(elHalf.value) || 1);
        return Math.LN2 / h;
    }

    // Recommended threshold from the sliders: the top-X% boundary reaches the threshold in Y days.
    function recommend() {
        if (N === 0) { return 0; }
        var lam = lambda();
        var share = Math.max(1, Number(elShare.value) || 1);
        var yDays = (Math.max(1, Number(elHor.value) || 1)) * 30;

        var idx = Math.min(N - 1, Math.max(0, Math.ceil(N * share / 100) - 1));
        var rX = users[idx].rate;                 // rate at the share boundary
        var eq = rX / lam;                        // equilibrium score for that rate
        return eq * (1 - Math.exp(-lam * yDays)); // reached in Y days
    }

    function renderList(T) {
        var lam = lambda();
        var rows = '';
        var count = 0;
        for (var i = 0; i < N; i++) {
            var u = users[i];
            var eq = u.rate / lam;                 // equilibrium score
            if (eq < T) { continue; }              // won't become an elder
            count++;
            // days to threshold: t = -ln(1 - T/eq) / λ
            var ratio = (eq > 0) ? (1 - T / eq) : 0;
            var days = (ratio > 0) ? Math.ceil(-Math.log(ratio) / lam) : 0;
            var daysTxt = (isFinite(days) && days < 100000) ? days : (i18n.inf || '∞');
            var nm = u.username ? '@' + esc(u.username) : esc(i18n.noUser || '');
            rows += '<tr><td><strong>' + nm + '</strong></td>'
                  + '<td>' + (Math.round(u.rate * 100) / 100) + '</td>'
                  + '<td>' + (Math.round(eq * 10) / 10) + '</td>'
                  + '<td>' + daysTxt + '</td></tr>';
        }
        body.innerHTML = rows || ('<tr><td colspan="4" class="has-text-centered has-text-grey py-4">—</td></tr>');
        if (elCount) {
            elCount.textContent = (i18n.count || '{n}').replace('{n}', count);
        }
    }

    // Sliders → pick the threshold and recompute the list.
    function fromSliders() {
        nShare.value = elShare.value;
        nHor.value   = elHor.value;
        nHalf.value  = elHalf.value;
        var T = Math.round(recommend() * 100) / 100;
        elThr.value = T;
        renderList(T);
    }

    // Manual threshold edit → recompute the list only.
    function fromThreshold() {
        renderList(Number(elThr.value) || 0);
    }

    [elShare, elHor, elHalf].forEach(function (el) {
        if (el) { el.addEventListener('input', fromSliders); }
    });
    // Number → slider (precise entry of round values).
    [[nShare, elShare], [nHor, elHor], [nHalf, elHalf]].forEach(function (pair) {
        if (pair[0] && pair[1]) {
            pair[0].addEventListener('input', function () { pair[1].value = pair[0].value; fromSliders(); });
        }
    });
    if (elThr) { elThr.addEventListener('input', fromThreshold); }

    fromSliders();
})();
