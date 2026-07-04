<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Migration 001_initial / 02 — initial cron scheduler rows.
 * Executed by the runner (migrations/run.php) via include; DB::, Logger:: etc. are available.
 *
 * INSERT IGNORE — idempotent: existing tasks are left untouched.
 * next_run_at is computed by the DB in UTC (session set to +00:00):
 *   daily tasks — tomorrow at 03:00 UTC; the rest — in N minutes.
 */

$cron = DB::table('cron_schedule');

$seed = [
    'score_recalc'         => "TIMESTAMP(UTC_DATE() + INTERVAL 1 DAY, '03:00:00')",
    'data_ttl'             => "TIMESTAMP(UTC_DATE() + INTERVAL 1 DAY, '03:00:00')",
    'vote_timeouts'        => 'NOW() + INTERVAL 1 MINUTE',
    'pending_setup_ttl'    => 'NOW() + INTERVAL 1 MINUTE',
    'bot_messages_cleanup' => 'NOW() + INTERVAL 1 MINUTE',
    'reentry_check'        => 'NOW() + INTERVAL 5 MINUTE',
];

foreach ($seed as $task => $expr) {
    DB::run("INSERT IGNORE INTO {$cron} (task, next_run_at) VALUES (?, {$expr})", [$task]);
}
