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
 *           On the FIRST run (SUPERADMIN_TOKEN still empty) no token is needed: a form asks you to
 *           create the superadmin login/password and writes SUPERADMIN_TOKEN + SUPERADMIN_PATH into
 *           config/bot.php, then the install continues.
 *   - CLI:  php install.php            (php install.php nowebhook — without webhook)
 *           php install.php gentoken [login]  — just print superadmin credentials to paste.
 *
 * ⚠️ After a successful install, DELETE install.php from the server.
 */

require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// CLI helper: `php install.php gentoken [login]` — print superadmin credentials to paste.
if ($isCli && in_array('gentoken', $argv, true)) {
    superadmin_gentoken_cli($argv);
    exit;
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    // We are NOT a full web installer. config/bot.php (with BOT_TOKEN, APP_URL, …) and config/db.php
    // must already exist and be filled in. The first-run form only writes the superadmin credentials
    // INTO an existing config/bot.php — it never creates the file. So if it's missing, stop with a
    // clear message instead of pretending we can bootstrap.
    if (!is_file(OSTRAKON_ROOT . '/config/bot.php')) {
        http_response_code(500);
        echo "config/bot.php not found.\n\n";
        echo "This installer does NOT create it. First:\n";
        echo "  1) cp config/bot.example.php config/bot.php  — fill in BOT_TOKEN, BOT_USERNAME, APP_URL, WEBHOOK_SECRET\n";
        echo "  2) cp config/db.example.php  config/db.php   — fill in the database credentials\n";
        echo "Leave SUPERADMIN_TOKEN empty; then re-open install.php and it will ask you to create it.\n";
        exit;
    }

    $superToken = (string) Config::value('bot', 'SUPERADMIN_TOKEN', '');
    if ($superToken === '') {
        // First run, credentials not set yet: let the deployer create them. This is the only
        // window where install.php is reachable without a token — set the password, then delete
        // install.php. On success this returns and the installation continues below.
        superadmin_bootstrap_web();
    } else {
        $given = (string) ($_GET['token'] ?? '');
        if (!hash_equals($superToken, $given)) {
            http_response_code(403);
            echo "Forbidden.\n";
            echo "Run: install.php?token=YOUR_SUPERADMIN_TOKEN (value from config/bot.php)\n";
            exit;
        }
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

// ---------------------------------------------------------------------------
// Superadmin credentials (SUPERADMIN_TOKEN = base64("login:password"), + a secret
// SUPERADMIN_PATH). Set on first run: a web form (no CLI on most shared hosting) or
// `php install.php gentoken`.
// ---------------------------------------------------------------------------

/** A strong-ish random password (unambiguous alphabet, no easily-confused characters). */
function superadmin_gen_password(int $len = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** A random secret slug for SUPERADMIN_PATH (18 hex chars). */
function superadmin_gen_slug(): string
{
    return bin2hex(random_bytes(9));
}

/** Minimal password-strength check. Returns an error message, or null if acceptable. */
function superadmin_weak_password(string $p): ?string
{
    if (strlen($p) < 10) {
        return 'password must be at least 10 characters';
    }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
        return 'password must contain at least one letter and one digit';
    }
    return null;
}

/**
 * Write "KEY => 'value'" pairs into config/bot.php. Values must be single-quote-safe — base64 and
 * hex slugs are. Replaces the existing line for a key, or inserts before the final "];". Returns
 * false if the file isn't writable or the write fails.
 * @param array<string, string> $kv
 */
function superadmin_write_bot_config(array $kv): bool
{
    $path = OSTRAKON_ROOT . '/config/bot.php';
    if (!is_file($path) || !is_writable($path)) {
        return false;
    }
    $src = (string) file_get_contents($path);
    foreach ($kv as $key => $val) {
        $replacement = "'" . $val . "'";
        $pattern = "/('" . preg_quote($key, '/') . "'\s*=>\s*)'[^']*'/";
        if (preg_match($pattern, $src)) {
            $src = (string) preg_replace_callback($pattern, static fn(array $m): string => $m[1] . $replacement, $src, 1);
        } else {
            $src = (string) preg_replace('/\n\];\s*$/', "\n    '" . $key . "' => " . $replacement . ",\n];\n", $src, 1);
        }
    }
    return file_put_contents($path, $src) !== false;
}

/** CLI: `php install.php gentoken [login]` — print credentials to paste into config/bot.php. */
function superadmin_gentoken_cli(array $argv): void
{
    $login = 'admin';
    $idx = array_search('gentoken', $argv, true);
    if ($idx !== false && isset($argv[$idx + 1]) && $argv[$idx + 1] !== 'nowebhook') {
        $login = (string) $argv[$idx + 1];
    }
    if (str_contains($login, ':')) {
        echo "Login must not contain ':'.\n";
        return;
    }
    $password    = superadmin_gen_password();
    $token       = base64_encode($login . ':' . $password);
    $slug        = superadmin_gen_slug();
    $workerToken = bin2hex(random_bytes(24));
    $appUrl      = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');

    echo "=== Superadmin credentials ===\n\n";
    echo "  login:    {$login}\n";
    echo "  password: {$password}\n\n";
    echo "Put these into config/bot.php:\n\n";
    echo "  'SUPERADMIN_TOKEN' => '{$token}',\n";
    echo "  'SUPERADMIN_PATH'  => '{$slug}',\n";
    echo "  'WORKER_TOKEN'     => '{$workerToken}',\n\n";
    echo "Superadmin page: " . ($appUrl !== '' ? $appUrl : 'APP_URL') . "/{$slug}\n";
    echo "Log in with the login/password above (the browser's Basic Auth prompt).\n";
    echo "Keep the password — it is NOT stored anywhere in plain text.\n";
}

/**
 * Web bootstrap (SUPERADMIN_TOKEN still empty): let the deployer create superadmin credentials.
 * GET → show a form; POST → validate, write SUPERADMIN_TOKEN + a random SUPERADMIN_PATH into
 * config/bot.php, then RETURN so the installer continues. If the config isn't writable, print the
 * values for a manual paste and exit.
 */
function superadmin_bootstrap_web(): void
{
    // Writability is checked UP FRONT (before asking for anything): if config/bot.php can't be
    // written, we don't pretend — the form just computes the values for a manual paste.
    $writable = is_writable(OSTRAKON_ROOT . '/config/bot.php');

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        superadmin_bootstrap_form(null, $writable);
        exit;
    }

    $login = trim((string) ($_POST['login'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    $error = null;
    if ($login === '' || str_contains($login, ':')) {
        $error = "Login is required and must not contain ':'.";
    } elseif ($pass !== $pass2) {
        $error = 'The two passwords do not match.';
    } elseif (($w = superadmin_weak_password($pass)) !== null) {
        $error = ucfirst($w) . '.';
    }
    if ($error !== null) {
        superadmin_bootstrap_form($error, $writable);
        exit;
    }

    $token = base64_encode($login . ':' . $pass);
    // Keep an already-configured secret path; generate one only if it's still empty.
    $existingPath = trim((string) Config::value('bot', 'SUPERADMIN_PATH', ''));
    $slug = $existingPath !== '' ? $existingPath : superadmin_gen_slug();
    $toWrite = ['SUPERADMIN_TOKEN' => $token];
    if ($existingPath === '') {
        $toWrite['SUPERADMIN_PATH'] = $slug;
    }

    if (!$writable || !superadmin_write_bot_config($toWrite)) {
        // Can't (or couldn't) write — hand over the exact line(s) to paste. Works without any CLI.
        header('Content-Type: text/plain; charset=utf-8');
        echo "config/bot.php is not writable — set the value(s) by hand:\n\n";
        echo "  'SUPERADMIN_TOKEN' => '{$token}',\n";
        if ($existingPath === '') {
            echo "  'SUPERADMIN_PATH'  => '{$slug}',\n";
        }
        echo "\nThen re-open install.php?token={$token}\n";
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    $appUrl = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
    out('✓ Superadmin credentials saved to config/bot.php');
    out('  superadmin page: ' . ($appUrl !== '' ? $appUrl : 'APP_URL') . '/' . $slug);
    out('  (log in with the login/password you just chose)');
    out('');
    // return → the installer proceeds to steps [1/4]…[4/4] below.
}

/** Render the minimal first-run HTML form (optionally with an error; $writable tunes the wording). */
function superadmin_bootstrap_form(?string $error, bool $writable): void
{
    header('Content-Type: text/html; charset=utf-8');
    $err = $error !== null
        ? '<p style="color:#b00020;font-weight:bold">' . htmlspecialchars($error, ENT_QUOTES) . '</p>'
        : '';
    $notice = $writable
        ? '<p>I will write the values into <code>config/bot.php</code> for you.</p>'
        : '<p style="color:#b8860b"><strong>Note:</strong> <code>config/bot.php</code> is not writable, '
          . 'so I can\'t save it automatically. Enter a login and password and I\'ll show you the exact '
          . 'lines to paste in yourself.</p>';
    $button = $writable ? 'Create &amp; continue' : 'Show values to paste';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Ostrakon — first run</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:3rem auto;padding:0 1rem;line-height:1.5">'
       . '<h1>Ostrakon — first run</h1>'
       . '<p>Create the <strong>superadmin</strong> login and password. They protect the operator '
       . 'page and gate <code>cron.php</code> / <code>install.php</code> / <code>migrations</code>. '
       . 'The password is <strong>not</strong> stored in plain text — remember it.</p>'
       . $notice
       . $err
       . '<form method="post" autocomplete="off">'
       . '<p><label>Login<br><input name="login" value="admin" style="width:100%;padding:.45rem"></label></p>'
       . '<p><label>Password<br><input name="password" type="password" style="width:100%;padding:.45rem"></label></p>'
       . '<p><label>Repeat password<br><input name="password2" type="password" style="width:100%;padding:.45rem"></label></p>'
       . '<p><button type="submit" style="padding:.55rem 1.2rem">' . $button . '</button></p>'
       . '</form></body></html>';
}

/**
 * Register the bot's DM command menu via setMyCommands, once per available language (localized
 * descriptions) plus a default. Scope: all private chats. Returns true if every call succeeded.
 */
function register_bot_commands(): bool
{
    $commands  = ['start', 'groups', 'language', 'help', 'privacy'];
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

// Ensure a WORKER_TOKEN exists: a separate low-privilege secret used ONLY to trigger the worker
// (cron.php / the webhook self-poke). Generated once and written to config/bot.php; it never
// reuses SUPERADMIN_TOKEN, so a cron URL that leaks into access logs can't expose the master secret.
$workerToken = (string) Config::value('bot', 'WORKER_TOKEN', '');
if ($workerToken === '') {
    $workerToken = bin2hex(random_bytes(24));
    if (superadmin_write_bot_config(['WORKER_TOKEN' => $workerToken])) {
        out('  ✓ WORKER_TOKEN generated and saved to config/bot.php');
    } else {
        out('  • config/bot.php not writable — add this line yourself:');
        out("      'WORKER_TOKEN' => '{$workerToken}',");
    }
}
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
