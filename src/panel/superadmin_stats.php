<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Superadmin (operator) telemetry: aggregate usage metrics across all groups, over three windows
 * (7 days / 30 days / all time). Its own page (reached via ?view=stats on the secret SUPERADMIN_PATH,
 * behind the same HTTP Basic Auth) so the overview stays compact.
 * @var array<string, array{d7:int, d30:int, all:int}> $stats   telemetry counts per event
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();

$superUrl = $base . '/' . rawurlencode(trim((string) Config::value('bot', 'SUPERADMIN_PATH', ''), '/'));

// Telemetry rows, in display order: [section-title-key => [event => label-key, …]].
$statSections = [
    'panel_stats_bot' => [
        'bot_added'         => 'stat_bot_added',
        'bot_admin_granted' => 'stat_bot_admin_granted',
        'bot_removed'       => 'stat_bot_removed',
        'bot_left'          => 'stat_bot_left',
    ],
    'panel_stats_votes' => [
        'vote_created'         => 'stat_vote_created',
        'vote_banned_vote'     => 'stat_vote_banned_vote',
        'vote_declined_vote'   => 'stat_vote_declined_vote',
        'vote_banned_admin'    => 'stat_vote_banned_admin',
        'vote_declined_admin'  => 'stat_vote_declined_admin',
        'vote_cancelled_admin' => 'stat_vote_cancelled_admin',
        'vote_banned_manual'   => 'stat_vote_banned_manual',
        'vote_expired'         => 'stat_vote_expired',
        'vote_cancelled'       => 'stat_vote_cancelled',
    ],
];
/** Count for an event/window, 0 when the event has never fired. */
$stat = static function (string $event, string $win) use ($stats): int {
    return (int) ($stats[$event][$win] ?? 0);
};
?>
<div class="box">
    <div class="level is-mobile">
        <div class="level-left">
            <div>
                <h1 class="title is-4"><?= htmlspecialchars(Lang::get('panel_stats', $lng)) ?></h1>
                <p class="has-text-grey"><?= htmlspecialchars(Lang::get('panel_stats_intro', $lng)) ?></p>
            </div>
        </div>
        <div class="level-right">
            <a class="button is-light" href="<?= htmlspecialchars($superUrl) ?>"><?= htmlspecialchars(Lang::get('panel_stats_back', $lng)) ?></a>
        </div>
    </div>
</div>

<div class="box">
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(Lang::get('panel_stats_col_metric', $lng)) ?></th>
                    <th class="has-text-right"><?= htmlspecialchars(Lang::get('panel_stats_col_7d', $lng)) ?></th>
                    <th class="has-text-right"><?= htmlspecialchars(Lang::get('panel_stats_col_30d', $lng)) ?></th>
                    <th class="has-text-right"><?= htmlspecialchars(Lang::get('panel_stats_col_all', $lng)) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statSections as $sectionKey => $events): ?>
                    <tr>
                        <th colspan="4" class="has-background-light"><?= htmlspecialchars(Lang::get($sectionKey, $lng)) ?></th>
                    </tr>
                    <?php foreach ($events as $event => $labelKey): ?>
                        <tr>
                            <td><?= htmlspecialchars(Lang::get($labelKey, $lng)) ?></td>
                            <td class="has-text-right"><?= $stat($event, 'd7') ?></td>
                            <td class="has-text-right"><?= $stat($event, 'd30') ?></td>
                            <td class="has-text-right"><?= $stat($event, 'all') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
