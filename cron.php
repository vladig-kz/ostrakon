<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * cron.php — the Ostrakon worker.
 *
 * The host has no fastcgi_finish_request, so update processing is moved here out of
 * webhook.php. The worker is launched by the system cron (every minute) and loops almost
 * until the next launch (worker_loop_seconds); every worker_poll_seconds it:
 *   1) drains the update_queue → Handler::handle (incoming Telegram updates);
 *   2) runs any due scheduled tasks (cron_schedule).
 *
 * A flock prevents two workers from running at once: if the previous one is still alive,
 * the new one exits immediately. The run duration comes from config; the schedule period
 * doesn't need to be known.
 *
 * Launch: system cron once a minute — CLI `php cron.php` (preferred for a long-running
 * process) or URL `cron.php?token=SUPERADMIN_TOKEN`.
 */

require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = (string) Config::value('bot', 'SUPERADMIN_TOKEN', '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

// Protect against overlapping runs.
$lockHandle = cron_acquire_lock();
if ($lockHandle === null) {
    echo "BUSY\n";
    exit;
}

@set_time_limit(0);
ignore_user_abort(true);

// defaults.php is a NESTED config: worker_* live under the 'instance' section, so read that
// sub-array and index into it (Config::value only resolves ONE key level — see cron_run() below,
// which reads cron_intervals the same way).
$instance    = (array) Config::value('defaults', 'instance', []);
$loopSeconds = max(1, (int) ($instance['worker_loop_seconds'] ?? 55));
$pollSeconds = max(1, (int) ($instance['worker_poll_seconds'] ?? 2));
$heartbeat   = (bool) ($instance['worker_heartbeat'] ?? false);
$start       = time();
$deadline    = $start + $loopSeconds;

$updates = 0;
$tasks   = 0;
do {
    try {
        $updates += worker_process_queue();  // incoming updates first
        $tasks   += cron_run();              // then due scheduled tasks
    } catch (Throwable $e) {
        Logger::fatal('cron: unhandled exception in worker loop', $e);
    }

    // Heartbeat (debug): shows the worker is alive and how far into the loop it got. If these lines
    // stop before "worker finished", the host killed the process at that elapsed second.
    if ($heartbeat) {
        Logger::info('cron: heartbeat', ['elapsed' => time() - $start, 'updates' => $updates, 'tasks' => $tasks]);
    }

    if (time() >= $deadline) {
        break;
    }
    sleep($pollSeconds);
} while (time() < $deadline);

if ($heartbeat) {
    Logger::info('cron: worker finished', ['elapsed' => time() - $start, 'updates' => $updates, 'tasks' => $tasks]);
}
echo "OK (updates: {$updates}, tasks: {$tasks})\n";

// ---------------------------------------------------------------------------
// Incoming update queue
// ---------------------------------------------------------------------------

/**
 * Drain the update_queue: for each update call the Handler, then delete the row.
 * The flock guarantees a single worker, so there are no races. Returns the count processed.
 */
function worker_process_queue(): int
{
    $t = DB::table('update_queue');
    $rows = DB::fetchAll("SELECT id, payload FROM {$t} ORDER BY id LIMIT 50");

    $count = 0;
    foreach ($rows as $row) {
        $update = json_decode((string) $row['payload'], true);
        if (is_array($update)) {
            try {
                Handler::handle($update);
            } catch (Throwable $e) {
                Logger::error('cron: failed to process queued update', $e, ['queue_id' => $row['id']]);
            }
        }
        // Delete regardless — a "poison" update must not get stuck in the queue.
        DB::run("DELETE FROM {$t} WHERE id = ?", [(int) $row['id']]);
        $count++;
    }
    return $count;
}

// ---------------------------------------------------------------------------
// Scheduled tasks (cron_schedule)
// ---------------------------------------------------------------------------

/** Run all due tasks. Returns the count executed. */
function cron_run(): int
{
    /** @var array<string, int> $intervals task intervals in seconds */
    $intervals = (array) Config::value('defaults', 'cron_intervals', []);

    $table = DB::table('cron_schedule');
    $due = DB::fetchAll(
        "SELECT task FROM {$table} WHERE next_run_at <= NOW() ORDER BY next_run_at"
    );
    if ($due === []) {
        return 0;
    }

    $count = 0;
    foreach ($due as $row) {
        $task = (string) $row['task'];
        Logger::info("cron: running task '{$task}'");
        try {
            cron_dispatch($task);
            $count++;
        } catch (Throwable $e) {
            Logger::error("cron: error in task '{$task}'", $e);
        }

        $interval = (int) ($intervals[$task] ?? 0);
        if ($interval <= 0) {
            Logger::warning("cron: interval for task '{$task}' not set, deferring by an hour");
            $interval = 3600;
        }
        DB::run(
            "UPDATE {$table} SET next_run_at = NOW() + INTERVAL ? SECOND WHERE task = ?",
            [$interval, $task]
        );
    }
    return $count;
}

/** Route a scheduled task to its handler. */
function cron_dispatch(string $task): void
{
    switch ($task) {
        case 'vote_timeouts':
            VoteManager::runTimeouts();
            break;

        case 'bot_messages_cleanup':
            VoteManager::cleanupBotMessages();
            break;

        case 'onboarding_check':
            GroupManager::runOnboardingChecks();
            break;

        case 'reentry_check':
            // Clear expired re-entry windows (the live auto-kick happens in onJoin).
            $cleared = GroupManager::clearExpiredReentry();
            Logger::debug('cron: reentry_check', ['cleared' => $cleared]);
            break;

        case 'score_recalc':
            ScoreManager::recalcAll();
            break;

        case 'data_ttl':
            $instance = (array) Config::value('defaults', 'instance', []);
            $days     = (int) ($instance['history_days'] ?? 365);
            $msgs  = ScoreManager::purgeOldMessages($days);
            $votes = VoteManager::purgeOldVotes($days);
            $acts  = Notifier::purgeOldActions($days);
            Logger::info('cron: data_ttl', ['days' => $days, 'messages' => $msgs, 'votes' => $votes, 'notify_actions' => $acts]);
            break;

        default:
            Logger::warning("cron: unknown task '{$task}' in cron_schedule");
    }
}

/**
 * Acquire the exclusive run lock (advisory flock, NOT a PID file).
 * Released automatically when the script ends. The file lives in logs/ (guaranteed
 * writable); there is no need to delete it.
 *
 * @return resource|null
 */
function cron_acquire_lock()
{
    $path = OSTRAKON_ROOT . '/logs/do_not_delete_this.lock';
    $fp = @fopen($path, 'c');
    if ($fp === false) {
        Logger::warning('cron: could not open lock file, run skipped');
        return null;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}
