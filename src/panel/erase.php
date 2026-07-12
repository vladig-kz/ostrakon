<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * "Erase a user's data" page (owner/managers only — checked in Panel).
 * Two steps: a search form (by @username or id), then a per-candidate confirmation.
 * @var array<string, mixed>                                       $group
 * @var string                                                     $query
 * @var bool                                                       $searched
 * @var array<int, array{user_id:int, username:string, name:string}> $candidates
 * @var string                                                     $csrf
 * @var string|null                                                $flash
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();
$cid  = (int) $group['chat_id'];
$eraseUrl = $base . '/admin/group/' . $cid . '/erase';
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $cid ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<?php if (!empty($flash)): ?>
    <div class="notification is-info is-light"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars(Lang::get('panel_erase_title', $lng)) ?></h1>
    <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('panel_erase_intro', $lng)) ?></p>

    <form method="post" action="<?= htmlspecialchars($eraseUrl) ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="field has-addons">
            <div class="control is-expanded">
                <input class="input" type="text" name="query" autocomplete="off"
                       placeholder="<?= htmlspecialchars(Lang::get('panel_erase_placeholder', $lng)) ?>"
                       value="<?= htmlspecialchars($query) ?>">
            </div>
            <div class="control">
                <button class="button is-link" type="submit"><?= htmlspecialchars(Lang::get('panel_erase_find', $lng)) ?></button>
            </div>
        </div>
    </form>
</div>

<?php if (!empty($searched)): ?>
    <?php if ($candidates === []): ?>
        <div class="notification is-warning is-light">
            <?= htmlspecialchars(Lang::get('panel_erase_none', $lng, ['q' => $query])) ?>
        </div>
    <?php else: ?>
        <div class="box">
            <?php if (count($candidates) > 1): ?>
                <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('panel_erase_multi', $lng)) ?></p>
            <?php endif; ?>
            <?php foreach ($candidates as $c): ?>
                <?php
                    $uname = (string) ($c['username'] ?? '');
                    $name  = (string) ($c['name'] ?? '');
                    $uid   = (int) $c['user_id'];
                ?>
                <div class="notification is-light mb-4">
                    <p class="mb-2">
                        <strong><?= $uname !== '' ? ('@' . htmlspecialchars($uname)) : htmlspecialchars(Lang::get('panel_no_username', $lng)) ?></strong>
                        <?php if ($name !== ''): ?>
                            <span class="has-text-grey"><?= htmlspecialchars($name) ?></span>
                        <?php else: ?>
                            <span class="has-text-grey is-size-7">(<?= htmlspecialchars(Lang::get('panel_erase_name_unknown', $lng)) ?>)</span>
                        <?php endif; ?>
                        <span class="has-text-grey is-size-7">· id <?= $uid ?></span>
                    </p>
                    <p class="has-text-grey is-size-7 mb-3"><?= htmlspecialchars(Lang::get('panel_erase_confirm_note', $lng)) ?></p>
                    <form method="post" action="<?= htmlspecialchars($eraseUrl) ?>">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button class="button is-danger" type="submit"><?= htmlspecialchars(Lang::get('panel_erase_delete', $lng)) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
