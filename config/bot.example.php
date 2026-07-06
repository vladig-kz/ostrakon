<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Ostrakon — bot and infrastructure configuration (TEMPLATE).
 *
 * Copy this file to config/bot.php and fill in the real values.
 * config/bot.php holds secrets and MUST NOT be committed to version control.
 */

return [

    /*
     * APP_URL — the full public address of the app (domain + path, NO trailing slash).
     * The single source for building ALL links: webhook, admin panel, Telegram Login
     * Widget, static assets.
     *
     * Both placements are supported:
     *   - on a subdomain: https://ostrakon.example.com
     *   - in a subfolder: https://example.com/ostrakon
     *
     * IMPORTANT: the code must not assume the bot lives at the domain root.
     * Every path is built as APP_URL . '/...'. Derived from APP_URL:
     *   webhook endpoint = APP_URL . '/webhook.php'
     *   admin panel      = APP_URL . '/admin/'
     *   cookie base path = the path part of APP_URL (for a subfolder — '/ostrakon')
     */
    'APP_URL' => 'https://example.com/ostrakon',

    // Telegram Bot API
    'BOT_TOKEN'    => '000000000:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', // token from @BotFather
    'BOT_USERNAME' => 'YourBot',                                       // without @; needed for the Login Widget and "@botname" texts

    // Webhook secret — passed to setWebhook and verified in the
    // X-Telegram-Bot-Api-Secret-Token header on every incoming update.
    'WEBHOOK_SECRET' => 'changeme-random-string',

    // Superadmin (operator) credentials — a single value that serves two purposes:
    //   1) gate for HTTP access to cron.php / install.php / migrations/run.php / log.php and for
    //      the worker self-trigger (passed as ?token=...);
    //   2) HTTP Basic Auth for the secret superadmin page (see SUPERADMIN_PATH below).
    // The value is base64("login:password") — exactly the Basic Auth format. Leave it EMPTY on a
    // fresh install: the first run of install.php (web) will ask you for a login + password and
    // write the value here for you. (Or generate it via CLI: `php install.php gentoken`.)
    'SUPERADMIN_TOKEN' => '',

    // Secret address of the superadmin page (a random slug, e.g. "a7f3k9d2c1..."). The page is
    // then served at APP_URL/<this-slug> and is protected by HTTP Basic Auth (SUPERADMIN_TOKEN).
    // Keeping the address secret is an extra layer on top of the password. Leave EMPTY to disable
    // the page entirely (any request is then a plain 404). install.php fills this in on first run.
    'SUPERADMIN_PATH' => '',

    // Logging level (threshold): trace | debug | info | warning | error | fatal.
    // Messages at or above the given severity are written. Use 'trace'/'debug' while
    // debugging, 'warning'/'error' in production. Log: logs/app.log (UTC time).
    'LOG_LEVEL' => 'debug',

];
