<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Help page (single page, with anchors for links from the panel).
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();

$sections = [
    'modes'        => ['help_modes_t', 'help_modes_b'],
    'privacy'      => ['help_privacy_t', 'help_privacy_b'],
    'voting'       => ['help_voting_t', 'help_voting_b'],
    'panel'        => ['help_panel_t', 'help_panel_b'],
    'elders'       => ['help_elders_t', 'help_elders_b'],
    'participants' => ['help_participants_t', 'help_participants_b'],
    'notifications'=> ['help_notify_t', 'help_notify_b'],
    'migration'    => ['help_migration_t', 'help_migration_b'],
];
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin"><?= htmlspecialchars(Lang::get('panel_to_home', $lng)) ?></a>

<h1 class="title is-3"><?= htmlspecialchars(Lang::get('panel_help', $lng)) ?></h1>

<?php foreach ($sections as $anchor => $keys): ?>
    <div class="box" id="<?= htmlspecialchars($anchor) ?>">
        <h2 class="title is-5"><?= htmlspecialchars(Lang::get($keys[0], $lng)) ?></h2>
        <?php // Escape first (safety), then turn our own **bold** marker into <strong>. ?>
        <p class="content"><?= preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', htmlspecialchars(Lang::get($keys[1], $lng))) ?></p>
    </div>
<?php endforeach; ?>
