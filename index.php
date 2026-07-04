<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * index.php — the single public entry point of the site (front controller).
 *
 * All "pretty" paths reach it via mod_rewrite (see .htaccess): real files
 * (webhook.php, cron.php, assets/…) are served directly, everything else comes here.
 * Roles by entry point: webhook.php — Telegram intake, cron.php — background worker,
 * index.php — the web router (admin panel). Panel logic and templates live under src/
 * (no direct web access), so this file is the only publicly reachable one.
 */

require __DIR__ . '/src/bootstrap.php';

Panel::run();
