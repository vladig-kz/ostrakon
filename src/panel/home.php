<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Panel home after login: greeting + the user's group list.
 * @var array{id:int, name:string, username:?string}  $user
 * @var array<int, array{chat_id:int, title:?string}> $groups
 * @var string                                         $addToGroup
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();
?>
<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars(Lang::get('panel_home_title', $lng)) ?></h1>
    <p class="has-text-grey">
        <?= htmlspecialchars(Lang::get('panel_logged_in_prefix', $lng)) ?>
        <strong><?= htmlspecialchars($user['name'] !== '' ? $user['name'] : '—') ?></strong><?php
        if (!empty($user['username'])): ?> (@<?= htmlspecialchars($user['username']) ?>)<?php endif; ?>.
    </p>
</div>

<div class="box">
    <h2 class="title is-5"><?= htmlspecialchars(Lang::get('panel_my_groups', $lng)) ?></h2>

    <?php if ($groups === []): ?>
        <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('panel_no_groups', $lng)) ?></p>
    <?php else: ?>
        <div class="menu mb-4">
            <ul class="menu-list">
                <?php foreach ($groups as $g): ?>
                    <?php $name = ($g['title'] !== null && $g['title'] !== '') ? $g['title'] : Lang::get('panel_group_untitled', $lng); ?>
                    <li>
                        <a href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $g['chat_id'] ?>">
                            ⚙️&nbsp; <?= htmlspecialchars($name) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($addToGroup !== ''): ?>
        <a class="button is-primary" href="<?= htmlspecialchars($addToGroup) ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars(Lang::get('panel_add_to_group', $lng)) ?>
        </a>
    <?php endif; ?>
</div>
