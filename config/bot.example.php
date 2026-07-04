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

    // Gate for HTTP access to cron.php / install.php / migrations/run.php and for the
    // worker self-trigger. NOT via Telegram.
    'SUPERADMIN_TOKEN' => 'changeme-superadmin-token',

    // Logging level (threshold): trace | debug | info | warning | error | fatal.
    // Messages at or above the given severity are written. Use 'trace'/'debug' while
    // debugging, 'warning'/'error' in production. Log: logs/app.log (UTC time).
    'LOG_LEVEL' => 'debug',

];
