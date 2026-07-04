<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Elder simulator (full mode): from the group's real activity, pick an elder_threshold for
 * the desired share of elders and horizon. Access checked in Panel.
 * @var array<string, mixed> $group a groups row
 * @var array{window:int, active_min:int, users:array} $stats activity stats
 * @var string      $csrf
 * @var string|null $flash
 */
$lng    = Panel::lang();
$base   = Panel::baseUrl();
$chatId = (int) $group['chat_id'];
$name   = (isset($group['title']) && (string) $group['title'] !== '')
    ? (string) $group['title']
    : Lang::get('panel_group_untitled', $lng);

$halflife = (int) ($group['halflife_days'] ?? 120);
$thr      = (float) ($group['elder_threshold'] ?? 50);
$hasData  = !empty($stats['users']);

$i18n = [
    'count'    => Lang::get('sim_count', $lng),
    'colUser'  => Lang::get('sim_col_user', $lng),
    'colRate'  => Lang::get('sim_col_rate', $lng),
    'colScore' => Lang::get('sim_col_score', $lng),
    'colDays'  => Lang::get('sim_col_days', $lng),
    'noUser'   => Lang::get('panel_no_username', $lng),
    'inf'      => '∞',
];
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>"><?= htmlspecialchars(Lang::get('panel_back_to_group', $lng)) ?></a>

<h1 class="title is-4"><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Lang::get('panel_simulator', $lng)) ?></h1>

<?php if (!empty($flash)): ?>
    <div class="notification is-success is-light"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="box">
    <p class="has-text-grey mb-4"><?= htmlspecialchars(Lang::get('sim_intro', $lng)) ?></p>

    <?php if (!$hasData): ?>
        <div class="notification is-warning is-light"><?= htmlspecialchars(Lang::get('sim_no_data', $lng)) ?></div>
    <?php else: ?>
        <p class="has-text-grey mb-4">
            <?= htmlspecialchars(Lang::get('sim_active_summary', $lng, [
                'n' => count($stats['users']), 'min' => $stats['active_min'], 'window' => $stats['window'],
            ])) ?>
        </p>

        <form method="post" action="<?= htmlspecialchars($base) ?>/admin/group/<?= $chatId ?>/simulator">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

            <div class="field">
                <label class="label"><?= htmlspecialchars(Lang::get('sim_target_share', $lng)) ?></label>
                <div class="sim-row">
                    <input id="s-share" class="sim-slider" type="range" min="5" max="100" step="5" value="25">
                    <input id="s-share-n" class="input sim-num" type="number" min="5" max="100" step="5" value="25">
                    <span class="sim-unit">%</span>
                </div>
                <p class="help"><?= htmlspecialchars(Lang::get('sim_help_share', $lng)) ?></p>
            </div>

            <div class="field">
                <label class="label"><?= htmlspecialchars(Lang::get('sim_horizon', $lng)) ?></label>
                <div class="sim-row">
                    <input id="s-horizon" class="sim-slider" type="range" min="1" max="24" step="1" value="3">
                    <input id="s-horizon-n" class="input sim-num" type="number" min="1" max="24" step="1" value="3">
                    <span class="sim-unit"><?= htmlspecialchars(Lang::get('sim_unit_months', $lng)) ?></span>
                </div>
                <p class="help"><?= htmlspecialchars(Lang::get('sim_help_horizon', $lng)) ?></p>
            </div>

            <div class="field">
                <label class="label"><?= htmlspecialchars(Lang::get('sim_halflife', $lng)) ?></label>
                <div class="sim-row">
                    <input id="s-half" class="sim-slider" type="range" min="7" max="365" step="1" value="<?= $halflife ?>">
                    <input id="s-half-n" name="halflife_days" class="input sim-num" type="number" min="7" max="365" step="1" value="<?= $halflife ?>">
                    <span class="sim-unit"><?= htmlspecialchars(Lang::get('sim_unit_days', $lng)) ?></span>
                </div>
                <p class="help"><?= htmlspecialchars(Lang::get('sim_help_halflife', $lng)) ?></p>
            </div>

            <div class="field">
                <label class="label"><?= htmlspecialchars(Lang::get('sim_recommended', $lng)) ?></label>
                <div class="control" style="max-width:220px;">
                    <input id="s-threshold" name="elder_threshold" class="input" type="number" step="0.01" min="0" value="<?= htmlspecialchars((string) $thr) ?>">
                </div>
                <p class="help"><?= htmlspecialchars(Lang::get('sim_recommended_hint', $lng)) ?></p>
            </div>

            <button type="submit" class="button is-primary"><?= htmlspecialchars(Lang::get('panel_save', $lng)) ?></button>
            <p class="help mt-2"><?= htmlspecialchars(Lang::get('sim_save_hint', $lng)) ?></p>
        </form>
    <?php endif; ?>
</div>

<?php if ($hasData): ?>
<div class="box">
    <h2 class="title is-5"><?= htmlspecialchars(Lang::get('sim_future_elders', $lng)) ?> — <span id="s-count"></span></h2>
    <p class="help mb-4">
        <?= htmlspecialchars(Lang::get('sim_help_eqscore', $lng)) ?>
        <?= htmlspecialchars(Lang::get('sim_help_days', $lng)) ?>
    </p>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(Lang::get('sim_col_user', $lng)) ?></th>
                    <th><?= htmlspecialchars(Lang::get('sim_col_rate', $lng)) ?></th>
                    <th><?= htmlspecialchars(Lang::get('sim_col_score', $lng)) ?></th>
                    <th><?= htmlspecialchars(Lang::get('sim_col_days', $lng)) ?></th>
                </tr>
            </thead>
            <tbody id="s-body"></tbody>
        </table>
    </div>
</div>

<script>
window.OSTRAKON_SIM = {
    users: <?= json_encode(array_values($stats['users']), JSON_UNESCAPED_UNICODE) ?>,
    i18n:  <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($base) ?>/assets/simulator.js"></script>
<?php endif; ?>
