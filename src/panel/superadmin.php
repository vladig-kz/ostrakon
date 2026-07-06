<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Superadmin (operator) overview: left — every group the bot serves (active first) with its last
 * activity; right — the owner/admins of the selected group, i.e. whom to notify (e.g. before a
 * shutdown). Reached at the secret SUPERADMIN_PATH behind HTTP Basic Auth (Panel::superadminPage).
 * @var array<int, array{chat_id:int, title:?string, is_active:int, last_activity:string}> $groups
 * @var array{chat_id:int, title:?string, is_active:int, last_activity:string}|null         $current
 * @var array<int, array{user_id:int, username:string, name:string, status:string, title:string}> $members
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();

/** UTC 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DD HH:MM' (seconds add no value here). */
$fmt = static function (string $ts): string {
    return $ts === '' ? '—' : substr($ts, 0, 16);
};
$untitled = Lang::get('panel_group_untitled', $lng);
?>
<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars(Lang::get('panel_hoster', $lng)) ?></h1>
    <p class="has-text-grey"><?= htmlspecialchars(Lang::get('panel_hoster_intro', $lng)) ?></p>
</div>

<div class="columns">
    <!-- Left: groups -->
    <div class="column is-5">
        <div class="box">
            <h2 class="title is-5"><?= htmlspecialchars(Lang::get('panel_hoster_groups', $lng)) ?></h2>
            <?php if ($groups === []): ?>
                <p class="has-text-grey"><?= htmlspecialchars(Lang::get('panel_hoster_no_groups', $lng)) ?></p>
            <?php else: ?>
                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(Lang::get('panel_hoster_col_group', $lng)) ?></th>
                                <th><?= htmlspecialchars(Lang::get('panel_hoster_col_activity', $lng)) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $g): ?>
                                <?php
                                    $isCur = $current !== null && $current['chat_id'] === $g['chat_id'];
                                    $name  = ($g['title'] !== null && $g['title'] !== '') ? $g['title'] : $untitled;
                                    $href  = $base . '/' . rawurlencode(trim((string) Config::value('bot', 'SUPERADMIN_PATH', ''), '/')) . '?g=' . $g['chat_id'];
                                ?>
                                <tr<?= $isCur ? ' class="is-selected"' : '' ?>>
                                    <td>
                                        <a href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($name) ?></a>
                                        <br>
                                        <?php if ((int) $g['is_active'] === 1): ?>
                                            <span class="tag is-success is-light"><?= htmlspecialchars(Lang::get('panel_hoster_active', $lng)) ?></span>
                                        <?php else: ?>
                                            <span class="tag is-light"><?= htmlspecialchars(Lang::get('panel_hoster_inactive', $lng)) ?></span>
                                        <?php endif; ?>
                                        <span class="has-text-grey is-size-7">#<?= (int) $g['chat_id'] ?></span>
                                    </td>
                                    <td class="is-size-7"><?= htmlspecialchars($fmt($g['last_activity'])) ?><br><span class="has-text-grey">UTC</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: who to notify -->
    <div class="column is-7">
        <div class="box">
            <h2 class="title is-5">
                <?= htmlspecialchars(Lang::get('panel_hoster_members', $lng)) ?>
                <?php if ($current !== null): ?>
                    <span class="has-text-grey is-size-6">— <?= htmlspecialchars(($current['title'] !== null && $current['title'] !== '') ? $current['title'] : $untitled) ?></span>
                <?php endif; ?>
            </h2>

            <?php if ($current === null): ?>
                <p class="has-text-grey"><?= htmlspecialchars(Lang::get('panel_hoster_pick', $lng)) ?></p>
            <?php elseif ($members === []): ?>
                <p class="has-text-grey"><?= htmlspecialchars(Lang::get('panel_hoster_no_members', $lng)) ?></p>
            <?php else: ?>
                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(Lang::get('panel_col_user', $lng)) ?></th>
                                <th><?= htmlspecialchars(Lang::get('panel_hoster_col_role', $lng)) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                                <?php $isOwner = $m['status'] === 'creator'; ?>
                                <tr>
                                    <td>
                                        <?php if ($m['username'] !== ''): ?>
                                            <a href="https://t.me/<?= htmlspecialchars(rawurlencode($m['username'])) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($m['username']) ?></a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($m['name'] !== '' ? $m['name'] : ('#' . $m['user_id'])) ?></span>
                                            <span class="has-text-grey is-size-7">(<?= htmlspecialchars(Lang::get('panel_no_username', $lng)) ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($m['title'] !== ''): ?>
                                            <br><span class="has-text-grey is-size-7"><?= htmlspecialchars($m['title']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isOwner): ?>
                                            <span class="tag is-warning"><?= htmlspecialchars(Lang::get('panel_owner_badge', $lng)) ?></span>
                                        <?php else: ?>
                                            <span class="tag is-info is-light"><?= htmlspecialchars(Lang::get('panel_admin_badge', $lng)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
