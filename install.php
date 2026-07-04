<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * install.php — the Ostrakon installer.
 *
 * It does:
 *   1. Requirement checks (PHP version, extensions, logs/ writability).
 *   2. Presence checks for config/db.php and config/bot.php.
 *   3. Applying migrations (schema + seed data) via Migrator
 *      — the same as migrations/run.php. The schema lives in migrations/001_initial.
 *   4. (Optionally) registering the webhook from APP_URL + WEBHOOK_SECRET.
 *
 * Run:
 *   - Web:  https://YOUR_DOMAIN/install.php?token=SUPERADMIN_TOKEN  (skip webhook: &webhook=0)
 *   - CLI:  php install.php            (php install.php nowebhook — without webhook)
 *
 * ⚠️ After a successful install, DELETE install.php from the server.
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
        echo "Run: install.php?token=YOUR_SUPERADMIN_TOKEN (value from config/bot.php)\n";
        exit;
    }
}

$registerWebhook = true;
if (!$isCli && ($_GET['webhook'] ?? '1') === '0') {
    $registerWebhook = false;
}
if ($isCli && in_array('nowebhook', $argv, true)) {
    $registerWebhook = false;
}

function out(string $line = ''): void
{
    echo $line . "\n";
}

function fail(string $line): never
{
    out('✗ ' . $line);
    out('');
    out('Installation aborted.');
    exit(1);
}

/**
 * Register the bot's DM command menu via setMyCommands, once per available language (localized
 * descriptions) plus a default. Scope: all private chats. Returns true if every call succeeded.
 */
function register_bot_commands(): bool
{
    $commands  = ['start', 'groups', 'language', 'help'];
    $scope     = ['type' => 'all_private_chats'];
    $available = array_keys(Lang::available());
    if ($available === []) {
        return false;
    }
    $default = in_array('en', $available, true) ? 'en' : $available[0];

    $ok = true;
    // null = the default set (no language_code); then one set per language.
    foreach (array_merge([null], $available) as $lc) {
        $useLang = $lc ?? $default;
        $list = [];
        foreach ($commands as $c) {
            $list[] = ['command' => $c, 'description' => Lang::get('cmd_desc_' . $c, $useLang)];
        }
        $params = ['commands' => $list, 'scope' => $scope];
        if ($lc !== null) {
            // Telegram wants a 2-letter ISO 639-1 code here; a file may use a regional code
            // (e.g. "kk-KZ") — send just "kk". Descriptions still use the file's full code.
            $params['language_code'] = substr($lc, 0, 2);
        }
        $ok = (Bot::call('setMyCommands', $params) !== null) && $ok;
    }
    return $ok;
}

out('=== Ostrakon — installation ===');
out('');

// ---------------------------------------------------------------------------
// 1. Requirements
// ---------------------------------------------------------------------------
out('[1/4] Checking requirements');

$hardFail = false;

if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    out('  ✓ PHP ' . PHP_VERSION);
} else {
    out('  ✗ PHP ' . PHP_VERSION . ' — 8.1+ required');
    $hardFail = true;
}

foreach (['pdo_mysql', 'curl', 'json'] as $ext) {
    if (extension_loaded($ext)) {
        out("  ✓ extension {$ext}");
    } else {
        out("  ✗ extension {$ext} not loaded");
        $hardFail = true;
    }
}

$logsDir = OSTRAKON_ROOT . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}
if (is_dir($logsDir) && is_writable($logsDir)) {
    out('  ✓ logs/ directory is writable');
} else {
    out('  ✗ logs/ directory is not writable');
    $hardFail = true;
}

if ($hardFail) {
    fail('Mandatory requirements not met (see above).');
}
out('');

// ---------------------------------------------------------------------------
// 2. Configs
// ---------------------------------------------------------------------------
out('[2/4] Checking configuration');

try {
    Config::get('db');
    out('  ✓ config/db.php found');
} catch (Throwable $e) {
    fail('config/db.php not found. Copy config/db.example.php → config/db.php and fill it in.');
}

try {
    Config::get('bot');
    out('  ✓ config/bot.php found');
} catch (Throwable $e) {
    fail('config/bot.php not found. Copy config/bot.example.php → config/bot.php and fill it in.');
}

$prefix = (string) Config::value('db', 'DB_TABLE_PREFIX', '');
out('  • table prefix: ' . ($prefix !== '' ? $prefix : '(empty)'));
out('');

// ---------------------------------------------------------------------------
// 3. Migrations (schema + seed data)
// ---------------------------------------------------------------------------
out('[3/4] Applying migrations');

try {
    DB::pdo(); // early connection check for a clear error message
} catch (Throwable $e) {
    Logger::error('install: could not connect to the database', $e);
    fail('Could not connect to the database: ' . $e->getMessage());
}

$result = Migrator::run();
foreach ($result['lines'] as $line) {
    out('  ' . $line);
}
if ($result['failed'] !== null) {
    fail("Migrations stopped at folder {$result['failed']} — see logs/app.log.");
}
out("  applied: {$result['applied']}, skipped: {$result['skipped']}");
out('');

// ---------------------------------------------------------------------------
// 4. Webhook registration
// ---------------------------------------------------------------------------
out('[4/4] Registering the webhook and bot commands');

if (!$registerWebhook) {
    out('  • webhook skipped (on request)');
} else {
    $appUrl = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
    $secret = (string) Config::value('bot', 'WEBHOOK_SECRET', '');

    if ($appUrl === '' || str_contains($appUrl, 'example.com')) {
        out('  • webhook skipped: APP_URL is not set (or is the sample). Register it manually (see README).');
    } else {
        $res = Bot::call('setWebhook', [
            'url'             => $appUrl . '/webhook.php',
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query', 'chat_member', 'my_chat_member'],
        ]);
        if ($res !== null) {
            out('  ✓ webhook registered: ' . $appUrl . '/webhook.php');
        } else {
            out('  ✗ failed to register the webhook — see logs/app.log. You can do it manually (README).');
        }
    }
}

// Register the DM command menu (setMyCommands), localized per available language. Scope is
// "all private chats" — the bot has no typed commands in groups (voting is by @mention).
register_bot_commands() ? out('  ✓ bot commands registered (/start, /groups, /language, /help)')
                        : out('  ✗ failed to register bot commands — see logs/app.log');
out('');

out('=== Installation complete ===');
out('');
out('⚠️  DELETE install.php from the server.');
