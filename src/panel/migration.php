<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Group "Migration" page: export (download JSON) + import (upload JSON).
 * Access checked in Panel::migrationPage (manager of this group).
 * @var array<string, mixed> $group      a groups table row
 * @var bool                 $hasHistory the group already holds finished votes
 * @var string               $csrf
 * @var string|null          $flash
 */
$lng    = Panel::lang();
$base   = Panel::baseUrl();
$chatId = (int) $group['chat_id'];
$name   = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<h1 class="title is-4"><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Lang::get('panel_migration', $lng)) ?></h1>

<?php if (!empty($flash)): ?>
    <div class="notification is-info is-light"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="box">
    <h2 class="title is-5"><?= htmlspecialchars(Lang::get('panel_export_title', $lng)) ?></h2>
    <p class="mb-4 has-text-grey"><?= htmlspecialchars(Lang::get('panel_export_hint', $lng)) ?></p>
    <a class="button is-primary" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>/export">
        <?= htmlspecialchars(Lang::get('panel_export_button', $lng)) ?>
    </a>
</div>

<div class="box">
    <h2 class="title is-5"><?= htmlspecialchars(Lang::get('panel_import_title', $lng)) ?></h2>
    <p class="mb-4 has-text-grey"><?= htmlspecialchars(Lang::get('panel_import_hint', $lng)) ?></p>
    <?php if ($hasHistory): ?>
        <div class="notification is-warning is-light"><?= htmlspecialchars(Lang::get('panel_import_has_history', $lng)) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data"
          action="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>/migration">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="field">
            <div class="control">
                <div class="file has-name">
                    <label class="file-label">
                        <input class="file-input" type="file" name="import_file" accept="application/json,.json" required>
                        <span class="file-cta">
                            <span class="file-label"><?= htmlspecialchars(Lang::get('panel_import_choose', $lng)) ?></span>
                        </span>
                        <span class="file-name" id="import-file-name"><?= htmlspecialchars(Lang::get('panel_import_no_file', $lng)) ?></span>
                    </label>
                </div>
            </div>
        </div>
        <button type="submit" class="button is-danger"><?= htmlspecialchars(Lang::get('panel_import_button', $lng)) ?></button>
    </form>
</div>

<script>
(function () {
    var input = document.querySelector('.file-input');
    var name  = document.getElementById('import-file-name');
    if (input && name) {
        input.addEventListener('change', function () {
            name.textContent = input.files.length ? input.files[0].name : name.textContent;
        });
    }
})();
</script>
