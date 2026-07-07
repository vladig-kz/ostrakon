<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * webhook.php — Telegram webhook entry point (asynchronous design).
 *
 * The host is cgi-fcgi without fastcgi_finish_request, so an early 200 isn't possible
 * and we must not hold the connection during processing (Telegram would time out and
 * retry). Solution: do the MINIMUM here — validate the secret, push the update into the
 * update_queue and return 200 right away. No Telegram calls here; the worker (cron.php)
 * drains the queue and runs the Handler.
 *
 * Deduplication: update_id is UNIQUE — a redelivered update is ignored.
 */

require __DIR__ . '/src/bootstrap.php';

// 1. Verify the webhook secret.
$expected = (string) Config::value('bot', 'WEBHOOK_SECRET', '');
$received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expected === '' || !hash_equals($expected, (string) $received)) {
    http_response_code(403);
    Logger::warning('webhook: bad secret token', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    exit;
}

// 2. Read the update.
$raw = (string) file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(200);
    exit;
}

// 3. Enqueue (no Telegram calls → responds in milliseconds). Duplicates by update_id.
try {
    DB::run(
        'INSERT IGNORE INTO ' . DB::table('update_queue') . ' (update_id, payload, created_at) VALUES (?, ?, NOW())',
        [(int) ($update['update_id'] ?? 0), $raw]
    );
    Logger::trace('webhook: update enqueued', ['update_id' => $update['update_id'] ?? null]);
} catch (Throwable $e) {
    Logger::error('webhook: failed to enqueue update', $e);
}

// Best-effort: poke the worker immediately (don't wait for the system cron).
// We don't wait for a response; if the self-request is blocked, the cron fallback drains the queue.
trigger_worker();

http_response_code(200);
echo 'OK';

/**
 * Start the cron.php worker via a non-blocking self-request. Short timeout: we only need
 * to initiate the request — cron.php continues on its own (ignore_user_abort). If a worker
 * is already running, the new one exits on the flock. All errors/timeouts are ignored.
 */
function trigger_worker(): void
{
    // Disabled by config → rely on the system cron alone (see 'worker_self_poke' in defaults.php).
    // worker_self_poke lives under the nested 'instance' section, so read that sub-array.
    $instance = (array) Config::value('defaults', 'instance', []);
    if (!(bool) ($instance['worker_self_poke'] ?? true)) {
        return;
    }
    $appUrl = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
    $token  = (string) Config::value('bot', 'SUPERADMIN_TOKEN', '');
    if ($appUrl === '' || $token === '') {
        return;
    }
    $ch = curl_init($appUrl . '/cron.php?token=' . urlencode($token));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT_MS     => 300,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
