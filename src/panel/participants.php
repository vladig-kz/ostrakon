<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Group "Participants" section: shell (Bulma) + config for the JS (assets/admin.js).
 * Access checked in Panel::participantsPage (admin of this group).
 * @var array<string, mixed> $group a groups table row
 * @var string               $csrf  CSRF token for actions
 */
$lng    = Panel::lang();
$base   = Panel::baseUrl();
$chatId = (int) $group['chat_id'];
$mode   = (string) ($group['mode'] ?? 'light');
$name   = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);

$i18n = [
    'protected'    => Lang::get('panel_status_protected', $lng),
    'banned'       => Lang::get('panel_status_banned', $lng),
    'active'       => Lang::get('panel_status_active', $lng),
    'protect'      => Lang::get('panel_act_protect', $lng),
    'unprotect'    => Lang::get('panel_act_unprotect', $lng),
    'unban'        => Lang::get('panel_act_unban', $lng),
    'empty'        => Lang::get('panel_empty', $lng),
    'loading'      => Lang::get('panel_loading', $lng),
    'failed'       => Lang::get('panel_action_failed', $lng),
    'confirmUnban' => Lang::get('panel_confirm_unban', $lng),
    'pageOf'       => Lang::get('panel_page_of', $lng),
    'prev'         => Lang::get('panel_prev', $lng),
    'next'         => Lang::get('panel_next', $lng),
    'noUsername'   => Lang::get('panel_no_username', $lng),
    'admin'        => Lang::get('panel_admin_badge', $lng),
    'owner'        => Lang::get('panel_owner_badge', $lng),
    'managerBadge' => Lang::get('panel_manager_badge', $lng),
    'grantManage'  => Lang::get('panel_act_grant_manage', $lng),
    'revokeManage' => Lang::get('panel_act_revoke_manage', $lng),
    'makeElder'    => Lang::get('panel_act_make_elder', $lng),
    'confirmMakeElder' => Lang::get('panel_confirm_make_elder', $lng),
];
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Lang::get('panel_participants', $lng)) ?></h1>

    <div class="field">
        <div class="control">
            <input type="search" id="p-search" class="input" placeholder="<?= htmlspecialchars(Lang::get('panel_search', $lng)) ?>" autocomplete="off">
        </div>
    </div>

    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th class="sortable" data-sort="username"><?= htmlspecialchars(Lang::get('panel_col_user', $lng)) ?></th>
                    <th class="sortable" data-sort="joined_at"><?= htmlspecialchars(Lang::get('panel_col_joined', $lng)) ?></th>
                    <?php if ($mode === 'full'): ?>
                        <th class="sortable" data-sort="score"><?= htmlspecialchars(Lang::get('panel_col_elder', $lng)) ?></th>
                        <th class="sortable" data-sort="msg_count"><?= htmlspecialchars(Lang::get('panel_col_msgs', $lng)) ?></th>
                    <?php endif; ?>
                    <th class="sortable" data-sort="is_protected"><?= htmlspecialchars(Lang::get('panel_col_status', $lng)) ?></th>
                    <th><?= htmlspecialchars(Lang::get('panel_col_actions', $lng)) ?></th>
                </tr>
            </thead>
            <tbody id="p-body"></tbody>
        </table>
    </div>

    <nav id="p-pager" class="pagination is-small" role="navigation" aria-label="pagination"></nav>
</div>

<script>
window.OSTRAKON = {
    apiUrl:    <?= json_encode($base . '/admin/api/group/' . $chatId . '/participants', JSON_UNESCAPED_SLASHES) ?>,
    actionUrl: <?= json_encode($base . '/admin/api/group/' . $chatId . '/participants/action', JSON_UNESCAPED_SLASHES) ?>,
    csrf:      <?= json_encode($csrf) ?>,
    mode:      <?= json_encode($mode) ?>,
    elderThreshold: <?= json_encode((float) ($group['elder_threshold'] ?? 0)) ?>,
    elderTitle:     <?= json_encode((string) ($group['elder_title'] ?? '')) ?>,
    i18n:      <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($base) ?>/assets/admin.js"></script>
