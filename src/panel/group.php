<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Group landing page (admin-only — checked in Panel::group).
 * @var array<string, mixed>                  $group     a groups table row
 * @var bool                                  $canManage viewer may manage settings
 * @var array{votes:bool,bans:bool,elders:bool} $notify  viewer's own notification flags
 * @var string                                $csrf
 * @var string|null                           $flash
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();
$name = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin"><?= htmlspecialchars(Lang::get('panel_back_to_groups', $lng)) ?></a>

<div class="box">
    <h1 class="title is-4"><?= htmlspecialchars($name) ?></h1>
    <p class="has-text-grey mb-4">
        chat_id <code><?= (int) $group['chat_id'] ?></code>
        · <?= htmlspecialchars(Lang::get('panel_group_mode', $lng)) ?>: <strong><?= htmlspecialchars((string) ($group['mode'] ?? '')) ?></strong>
    </p>

    <?php $canManage = !empty($canManage); $isOwner = !empty($isOwner); ?>
    <div class="buttons">
        <a class="button is-primary" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/participants">
            <?= htmlspecialchars(Lang::get('panel_open_participants', $lng)) ?>
        </a>
        <a class="button is-info" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/journal">
            <?= htmlspecialchars(Lang::get('panel_open_journal', $lng)) ?>
        </a>
        <?php if ($canManage): ?>
            <a class="button is-link" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/settings">
                <?= htmlspecialchars(Lang::get('panel_open_settings', $lng)) ?>
            </a>
            <?php if (($group['mode'] ?? 'light') === 'full'): ?>
                <a class="button is-success" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/simulator">
                    <?= htmlspecialchars(Lang::get('panel_open_simulator', $lng)) ?>
                </a>
            <?php endif; ?>
            <a class="button is-danger is-light" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/erase">
                <?= htmlspecialchars(Lang::get('panel_open_erase', $lng)) ?>
            </a>
        <?php endif; ?>
        <?php // Export/import is owner-only (it can rewrite all group data and historical flags). ?>
        <?php if ($isOwner): ?>
            <a class="button is-warning is-light" href="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/migration">
                <?= htmlspecialchars(Lang::get('panel_open_migration', $lng)) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($flash)): ?>
    <div class="notification is-info is-light"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php $notify = $notify ?? ['votes' => false, 'bans' => false, 'elders' => false]; ?>
<div class="box">
    <h2 class="title is-5"><?= htmlspecialchars(Lang::get('notify_title', $lng)) ?></h2>
    <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('notify_hint', $lng)) ?></p>
    <form method="post" action="<?= htmlspecialchars($base) ?>/admin/group/<?= (int) $group['chat_id'] ?>/notifications">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="notify_votes" <?= $notify['votes'] ? 'checked' : '' ?>>
                <?= htmlspecialchars(Lang::get('notify_opt_votes', $lng)) ?>
            </label>
        </div>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="notify_bans" <?= $notify['bans'] ? 'checked' : '' ?>>
                <?= htmlspecialchars(Lang::get('notify_opt_bans', $lng)) ?>
            </label>
        </div>
        <?php if (($group['mode'] ?? 'light') === 'full'): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="notify_elders" <?= $notify['elders'] ? 'checked' : '' ?>>
                    <?= htmlspecialchars(Lang::get('notify_opt_elders', $lng)) ?>
                </label>
            </div>
        <?php endif; ?>
        <div class="mt-4">
            <button type="submit" class="button is-primary"><?= htmlspecialchars(Lang::get('panel_save', $lng)) ?></button>
        </div>
    </form>
</div>

<?php if ($canManage): ?>
    <?php $loginLink = $base . '/admin/group/' . (int) $group['chat_id']; ?>
    <div class="box">
        <h2 class="title is-5"><?= htmlspecialchars(Lang::get('panel_login_link_title', $lng)) ?></h2>
        <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('panel_login_link_hint', $lng)) ?></p>
        <div class="field has-addons">
            <div class="control is-expanded">
                <input id="login-link" class="input" type="text" readonly value="<?= htmlspecialchars($loginLink) ?>">
            </div>
            <div class="control">
                <button id="copy-login-link" class="button is-link" type="button"
                        data-copied="<?= htmlspecialchars(Lang::get('panel_copied', $lng)) ?>">
                    <?= htmlspecialchars(Lang::get('panel_copy', $lng)) ?>
                </button>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('copy-login-link');
        var inp = document.getElementById('login-link');
        if (!btn || !inp) { return; }
        btn.addEventListener('click', function () {
            inp.select();
            var flash = function () {
                var t = btn.textContent;
                btn.textContent = btn.getAttribute('data-copied');
                setTimeout(function () { btn.textContent = t; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(inp.value).then(flash, function () { document.execCommand('copy'); flash(); });
            } else {
                document.execCommand('copy');
                flash();
            }
        });
    })();
    </script>
<?php endif; ?>
