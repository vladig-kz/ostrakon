<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * "Vote journal" section: shell (Bulma) + config for the JS (assets/journal.js).
 * Access checked in Panel::journalPage (admin of this group). Read-only.
 * @var array<string, mixed> $group a groups table row
 * @var string               $csrf  CSRF token (for unban)
 */
$lng    = Panel::lang();
$base   = Panel::baseUrl();
$chatId = (int) $group['chat_id'];
$name   = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);

$statuses = ['active', 'banned', 'declined', 'expired', 'cancelled'];

$i18n = [
    'status' => [
        'active'    => Lang::get('vote_status_active', $lng),
        'banned'    => Lang::get('vote_status_banned', $lng),
        'declined'  => Lang::get('vote_status_declined', $lng),
        'expired'   => Lang::get('vote_status_expired', $lng),
        'cancelled' => Lang::get('vote_status_cancelled', $lng),
    ],
    'empty'      => Lang::get('panel_jempty', $lng),
    'loading'    => Lang::get('panel_loading', $lng),
    'failed'     => Lang::get('panel_action_failed', $lng),
    'pageOf'     => Lang::get('panel_page_of', $lng),
    'prev'       => Lang::get('panel_prev', $lng),
    'next'       => Lang::get('panel_next', $lng),
    'noUsername' => Lang::get('panel_no_username', $lng),
    'dash'       => '—',
    'unban'        => Lang::get('panel_act_unban', $lng),
    'confirmUnban' => Lang::get('panel_confirm_unban', $lng),
];
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Lang::get('panel_journal', $lng)) ?></h1>

    <div class="field is-grouped">
        <div class="control is-expanded">
            <input type="search" id="j-search" class="input" placeholder="<?= htmlspecialchars(Lang::get('panel_jsearch', $lng)) ?>" autocomplete="off">
        </div>
        <div class="control">
            <div class="select">
                <select id="j-status">
                    <option value=""><?= htmlspecialchars(Lang::get('panel_jstatus_all', $lng)) ?></option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= $st ?>"><?= htmlspecialchars(Lang::get('vote_status_' . $st, $lng)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th class="sortable" data-sort="target_username"><?= htmlspecialchars(Lang::get('panel_jcol_target', $lng)) ?></th>
                    <th class="sortable" data-sort="initiator_username"><?= htmlspecialchars(Lang::get('panel_jcol_initiator', $lng)) ?></th>
                    <th class="sortable" data-sort="status"><?= htmlspecialchars(Lang::get('panel_jcol_status', $lng)) ?></th>
                    <th class="sortable" data-sort="for_sum"><?= htmlspecialchars(Lang::get('panel_jcol_for', $lng)) ?></th>
                    <th class="sortable" data-sort="against_sum"><?= htmlspecialchars(Lang::get('panel_jcol_against', $lng)) ?></th>
                    <th class="sortable" data-sort="started_at"><?= htmlspecialchars(Lang::get('panel_jcol_started', $lng)) ?></th>
                    <th class="sortable" data-sort="finished_at"><?= htmlspecialchars(Lang::get('panel_jcol_finished', $lng)) ?></th>
                    <th><?= htmlspecialchars(Lang::get('panel_col_actions', $lng)) ?></th>
                </tr>
            </thead>
            <tbody id="j-body"></tbody>
        </table>
    </div>

    <nav id="j-pager" class="pagination is-small" role="navigation" aria-label="pagination"></nav>
</div>

<script>
window.OSTRAKON = {
    apiUrl:    <?= json_encode($base . '/admin/api/group/' . $chatId . '/votes', JSON_UNESCAPED_SLASHES) ?>,
    actionUrl: <?= json_encode($base . '/admin/api/group/' . $chatId . '/participants/action', JSON_UNESCAPED_SLASHES) ?>,
    csrf:      <?= json_encode($csrf) ?>,
    i18n:      <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($base) ?>/assets/journal.js"></script>
