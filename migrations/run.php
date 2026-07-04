<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Ostrakon — run DB schema migrations (a wrapper around Migrator).
 *
 * Migrations are migrations/NNN_description folders with small files (*.sql/*.php).
 * Applied in name order, a folder at a time; tracked in {prefix}migrations.
 * See src/Migrator.php for details.
 *
 * Run:
 *   - Web: https://YOUR_DOMAIN/migrations/run.php?token=SUPERADMIN_TOKEN
 *   - CLI: php migrations/run.php
 */

require __DIR__ . '/../src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = (string) Config::value('bot', 'SUPERADMIN_TOKEN', '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden. Run: migrations/run.php?token=YOUR_SUPERADMIN_TOKEN\n";
        exit;
    }
}

echo "=== Ostrakon — migrations ===\n\n";

try {
    $r = Migrator::run();
} catch (Throwable $e) {
    echo '✗ Error: ' . $e->getMessage() . "\n";
    exit(1);
}

foreach ($r['lines'] as $line) {
    echo $line . "\n";
}

echo "\n=== Summary ===\n";
echo "Applied: {$r['applied']}\n";
echo "Skipped: {$r['skipped']}\n";
echo $r['failed'] !== null
    ? "FAILED at folder: {$r['failed']}\n"
    : "All migrations applied successfully.\n";
