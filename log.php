<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * log.php — view/clear the log (dev tool).
 *
 * Gated by DEV_TOKEN — a dedicated secret, empty by default (= endpoint disabled, answers 404).
 * The log file itself is blocked by .htaccess; we read it server-side.
 *
 *   Show the last N lines: log.php?token=YOUR_DEV_TOKEN&n=200
 *   Clear the log:         log.php?token=YOUR_DEV_TOKEN&clear=1
 *   CLI: php log.php [N] | php log.php clear
 *
 * Delete this endpoint in production (or just leave DEV_TOKEN empty, which disables it).
 */

require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');
DevAuth::gate();

$file = OSTRAKON_ROOT . '/logs/app.log';

// Clear the log.
$clear = $isCli ? in_array('clear', $argv, true) : (($_GET['clear'] ?? '') === '1');
if ($clear) {
    if (is_file($file)) {
        file_put_contents($file, '');
    }
    echo "Log cleared.\n";
    exit;
}

// How many trailing lines to show.
$n = $isCli ? (int) ($argv[1] ?? 200) : (int) ($_GET['n'] ?? 200);
if ($n <= 0) {
    $n = 200;
}
if ($n > 5000) {
    $n = 5000;
}

if (!is_file($file)) {
    echo "(log is empty)\n";
    exit;
}

$lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
echo implode("\n", array_slice($lines, -$n)) . "\n";
