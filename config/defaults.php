<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Ostrakon — default settings.
 *
 * schema_version  — DB schema version; bump on EVERY DDL change
 *                   (used for JSON export/import).
 * group           — default group settings; copied into the `groups` table when a group
 *                   is created. This is the single source of setting defaults (the DDL has none).
 * instance        — instance-level parameters (not per-group): history TTL, etc.
 * cron_intervals  — periodic task intervals in SECONDS. Not stored in the DB
 *                   (not changed at runtime). Used by the cron.php scheduler to move
 *                   next_run_at in the cron_schedule table.
 */

return [

    'schema_version' => 3,

    // -----------------------------------------------------------------
    // Default group settings (→ copied into the `groups` table)
    // -----------------------------------------------------------------
    'group' => [
        'mode'                    => 'light',   // light | full
        'min_age_hours'           => 72,        // tenure in the group required to vote
        'min_messages'            => 10,        // min counted messages (full only)
        'min_msg_length'          => 20,        // shorter → the message isn't counted (full)
        'msg_cooldown_minutes'    => 5,         // anti-flood between counted messages (full)
        'halflife_days'           => 120,       // half-life of a message's contribution to score
        'elder_threshold'         => 50.00,     // score ≥ this → elder
        'elder_weight'            => 3.00,      // an elder's vote weight
        'elder_title'             => 'аксакал', // status name (shown to users; Russian by default)
        'ban_threshold'           => 5.00,      // sum of "for" weights for a ban
        'readonly_ratio'          => 0.50,      // fraction of ban_threshold at which the target gets readonly
        'ban_decline_threshold'   => 5.00,      // sum of "against" weights for a decline
        'protected_ban_threshold' => 10.00,     // ban threshold when the TARGET is an elder (usually ban_threshold×2)
        'T1_hours'                => 24,         // timeout with no "against" votes
        'T2_hours'                => 72,         // timeout with "against" votes present (T2 >> T1)
        'cooldown_hours'          => 48,         // initiator protection from a counter-attack after a vote finishes
        'reentry_ban_hours'       => 168,        // auto-kick a re-entering banned user (7 days)
        'reentry_autokick'        => true,       // whether to kick a re-entering user in the reentry window: true=protect the decision, false=admin is king
        'cleanup_delay_seconds'   => 60,         // delete service messages after a vote finishes
        'reveal_delay_seconds'    => 60,         // delay before showing the final voter list on a ban
        'show_full_list'          => true,       // show the named list of voters in the ban result
        'delete_trigger_message'  => true,       // delete the bot activation message immediately
        'delete_spam_on_ban'      => true,       // delete the spam (trigger) message on a successful ban
        'admin_instant_ban'       => false,      // admin initiation: false=put to a vote (default), true=ban instantly
        'lang'                    => 'ru',       // ru | en | de | kk
    ],

    // -----------------------------------------------------------------
    // Instance-level parameters (not per-group)
    // -----------------------------------------------------------------
    'instance' => [
        'history_days' => 365,  // TTL for votes/vote_records/suspects/bot_messages

        // Worker (cron.php): how many seconds to loop per run and how often to poll the
        // queue. Run the system cron once a minute; keep loop_seconds a bit below the cron
        // period (≈ period − 5 sec). flock prevents overlap.
        'worker_loop_seconds' => 55,
        'worker_poll_seconds' => 2,
    ],

    // -----------------------------------------------------------------
    // Cron task intervals (seconds). Initial next_run_at is set by the installer.
    // -----------------------------------------------------------------
    'cron_intervals' => [
        'score_recalc'         => 86400,  // recompute score (daily, anchored at 03:00 UTC)
        'data_ttl'             => 86400,  // delete history older than history_days
        'vote_timeouts'        => 60,     // close timed-out votes (T1/T2)
        'pending_setup_ttl'    => 60,     // expire the "waiting for the bot to be added" state (10 min)
        'bot_messages_cleanup' => 60,     // deferred deletion of service messages
        'reentry_check'        => 300,    // clear expired re-entry windows of banned users
    ],

];
