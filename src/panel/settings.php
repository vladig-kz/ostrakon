<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Group settings: a form driven by GroupManager::settingsSchema().
 * Access checked in Panel::settingsPage (admin of this group).
 * @var array<string, mixed>                    $group  a groups table row
 * @var array<string, array<string, mixed>>     $schema the field schema
 * @var string                                  $csrf
 * @var string|null                             $flash
 */
$lng    = Panel::lang();
$base   = Panel::baseUrl();
$chatId = (int) $group['chat_id'];
$name   = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);

$sections = ['mode', 'voting', 'thresholds', 'timeouts', 'elder', 'behavior', 'lang'];

/** The "full" tag marking full-mode-only fields. */
$fullTag = ' <span class="tag is-light">' . htmlspecialchars(Lang::get('set_full_tag', $lng)) . '</span>';
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<h1 class="title is-4"><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Lang::get('panel_settings', $lng)) ?></h1>

<?php if (!empty($flash)): ?>
    <div class="notification is-success is-light"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>/settings">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <?php foreach ($sections as $sec): ?>
        <div class="box">
            <h2 class="title is-5">
                <?php if ($sec === 'elder'): ?>
                    <a href="<?= htmlspecialchars($base) ?>/admin/help#elders"><?= htmlspecialchars(Lang::get('setsec_elder', $lng)) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars(Lang::get('setsec_' . $sec, $lng)) ?>
                <?php endif; ?>
            </h2>

            <?php foreach ($schema as $key => $def): ?>
                <?php if (($def['section'] ?? '') !== $sec) { continue; } ?>
                <?php
                $val      = $group[$key] ?? '';
                $label    = htmlspecialchars(Lang::get('set_' . $key, $lng));
                $tag      = !empty($def['full']) ? $fullTag : '';
                $fullAttr = !empty($def['full']) ? ' data-full="1"' : '';
                $hintKey  = 'set_' . $key . '_hint';
                $hint     = Lang::has($hintKey, $lng)
                    ? '<p class="help">' . htmlspecialchars(Lang::get($hintKey, $lng)) . '</p>'
                    : '';
                ?>

                <?php if ($def['type'] === 'bool'): ?>
                    <div class="field"<?= $fullAttr ?>>
                        <label class="checkbox">
                            <input type="checkbox" name="<?= htmlspecialchars($key) ?>" value="1" <?= ((int) $val === 1) ? 'checked' : '' ?>>
                            <?= $label ?><?= $tag ?>
                        </label>
                        <?= $hint ?>
                    </div>

                <?php elseif ($def['type'] === 'select'): ?>
                    <div class="field"<?= $fullAttr ?>>
                        <label class="label"><?= $label ?><?= $tag ?></label>
                        <div class="control">
                            <div class="select">
                                <select name="<?= htmlspecialchars($key) ?>">
                                    <?php foreach ((array) $def['options'] as $ov => $ol): ?>
                                        <?php $disp = !empty($def['options_lang']) ? Lang::get((string) $ol, $lng) : (string) $ol; ?>
                                        <option value="<?= htmlspecialchars((string) $ov) ?>" <?= ((string) $val === (string) $ov) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($disp) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?= $hint ?>
                    </div>

                <?php else: ?>
                    <?php
                    $inputType = $def['type'] === 'text' ? 'text' : 'number';
                    $attrs = '';
                    if (isset($def['min'])) { $attrs .= ' min="' . htmlspecialchars((string) $def['min']) . '"'; }
                    if (isset($def['max'])) { $attrs .= ' max="' . htmlspecialchars((string) $def['max']) . '"'; }
                    if ($def['type'] === 'decimal') { $attrs .= ' step="any"'; }
                    if ($def['type'] === 'text' && isset($def['maxlen'])) { $attrs .= ' maxlength="' . (int) $def['maxlen'] . '"'; }
                    ?>
                    <div class="field"<?= $fullAttr ?>>
                        <label class="label"><?= $label ?><?= $tag ?></label>
                        <div class="control">
                            <input class="input" type="<?= $inputType ?>" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string) $val) ?>"<?= $attrs ?>>
                        </div>
                        <?= $hint ?>
                    </div>
                <?php endif; ?>

            <?php endforeach; ?>

            <?php if ($sec === 'mode'): ?>
                <p class="help">
                    <?= htmlspecialchars(Lang::get('set_mode_hint', $lng)) ?>.
                    <a href="<?= htmlspecialchars($base) ?>/admin/help#privacy"><?= htmlspecialchars(Lang::get('panel_help_more', $lng)) ?></a>.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="button is-primary is-medium"><?= htmlspecialchars(Lang::get('panel_save', $lng)) ?></button>
</form>

<script>
/* Hide full-mode fields when light is selected (fields stay in the form → not zeroed). */
(function () {
    var form = document.querySelector('form');
    if (!form) { return; }
    var mode = form.querySelector('[name="mode"]');
    if (!mode) { return; }

    function apply() {
        var light = mode.value === 'light';
        var full = form.querySelectorAll('.field[data-full="1"]');
        for (var i = 0; i < full.length; i++) {
            full[i].style.display = light ? 'none' : '';
        }
        var boxes = form.querySelectorAll('.box');
        for (var b = 0; b < boxes.length; b++) {
            var fields = boxes[b].querySelectorAll('.field');
            if (fields.length === 0) { continue; }
            var visible = false;
            for (var j = 0; j < fields.length; j++) {
                if (fields[j].style.display !== 'none') { visible = true; break; }
            }
            boxes[b].style.display = visible ? '' : 'none';
        }
    }

    mode.addEventListener('change', apply);
    apply();
})();
</script>
