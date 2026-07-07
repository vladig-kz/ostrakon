<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * inspect.php — DB inspector/editor (dev tool).
 *
 * Access: DevAuth (token once → cookie). CLI always allowed.
 *
 *   Overview (counts):        inspect.php?token=TOKEN            (then no token — cookie)
 *   Table dump:               inspect.php?table=votes&n=20
 *   Query (read AND write):   inspect.php?sql=SELECT * FROM {prefix}votes WHERE id=2
 *                             inspect.php?sql=UPDATE {prefix}groups SET ban_threshold=2
 *   CLI: php inspect.php  |  php inspect.php votes 20
 *
 * {prefix} in sql is replaced with DB_TABLE_PREFIX. One statement at a time (no ";").
 * Writes are allowed on purpose — this is a test instance. Do not ship this endpoint to prod.
 */

require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');
DevAuth::gate();

$prefix = (string) Config::value('db', 'DB_TABLE_PREFIX', '');

// Known tables → sort column (newest rows first).
$tables = [
    'groups'        => 'updated_at DESC',
    'participants'  => 'id DESC',
    'messages'      => 'id DESC',
    'votes'         => 'id DESC',
    'vote_records'  => 'id DESC',
    'suspects'      => 'id DESC',
    'cron_schedule' => 'next_run_at ASC',
    'users'         => 'updated_at DESC',
    'bot_messages'  => 'id DESC',
    'migrations'    => 'id DESC',
];

/** @param array<int, array<string,mixed>> $rows */
function rows_out(array $rows): void
{
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo '(' . count($rows) . " rows)\n";
}

$sql   = $isCli ? '' : trim((string) ($_GET['sql'] ?? ''));
$table = $isCli ? (string) ($argv[1] ?? '') : (string) ($_GET['table'] ?? '');
$n     = $isCli ? (int) ($argv[2] ?? 20) : (int) ($_GET['n'] ?? 20);
if ($n <= 0)   { $n = 20; }
if ($n > 1000) { $n = 1000; }

try {
    // 1) Arbitrary query (read or write).
    if ($sql !== '') {
        $q = rtrim(trim($sql), "; \t\n\r");
        if (str_contains($q, ';')) {
            echo "One statement at a time (no \";\").\n";
            exit;
        }
        $q = str_replace('{prefix}', $prefix, $q);

        if (preg_match('/^(select|show|describe|desc|explain)\b/i', $q)) {
            rows_out(DB::fetchAll($q));
        } else {
            $affected = DB::pdo()->exec($q);
            echo 'OK; rows affected: ' . ($affected === false ? '?' : $affected) . "\n";
        }
        exit;
    }

    // 2) Table dump.
    if ($table !== '') {
        if (!isset($tables[$table])) {
            echo "Unknown table. Available: " . implode(', ', array_keys($tables)) . "\n";
            exit;
        }
        $full = DB::table($table);
        rows_out(DB::fetchAll("SELECT * FROM {$full} ORDER BY {$tables[$table]} LIMIT {$n}"));
        exit;
    }

    // 3) Overview: PHP environment + counts.
    echo "=== PHP ===\n";
    echo 'sapi                   : ' . PHP_SAPI . "\n";
    echo 'fastcgi_finish_request : ' . (function_exists('fastcgi_finish_request') ? 'yes' : 'NO') . "\n";
    echo 'version                : ' . PHP_VERSION . "\n\n";

    echo "=== DB overview (prefix '{$prefix}') ===\n";
    foreach (array_keys($tables) as $t) {
        $full = DB::table($t);
        try {
            echo str_pad($t, 16) . ' : ' . DB::fetchColumn("SELECT COUNT(*) FROM {$full}") . "\n";
        } catch (Throwable $e) {
            echo str_pad($t, 16) . " : (no table)\n";
        }
    }
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
