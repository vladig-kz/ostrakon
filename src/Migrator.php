<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Migrator — applies DB schema migrations.
 *
 * migrations/NNN_* folders in ascending name order. A folder is applied as a whole: all of
 * its files (*.sql/*.php, by name) must succeed → a row is written to {prefix}migrations.
 * On error the folder is aborted and the process stops. Forward-only; rollback is manual.
 *
 * In *.sql: the {prefix} placeholder → DB_TABLE_PREFIX; line comments (-- ...) are stripped
 * and statements are split on ";". In *.php files DB::, Config::, Logger:: are available.
 *
 * run() prints nothing and checks no access — the caller does that
 * (migrations/run.php or install.php). It returns a struct with a human-readable log.
 *
 * @phpstan-return array{applied:int, skipped:int, failed:?string, lines:list<string>}
 */
final class Migrator
{
    /** @return array{applied:int, skipped:int, failed:?string, lines:array<int,string>} */
    public static function run(): array
    {
        $lines  = [];
        $prefix = (string) Config::value('db', 'DB_TABLE_PREFIX', '');
        $pdo    = DB::pdo();
        $tMig   = DB::table('migrations');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$tMig} (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                folder     VARCHAR(100) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_folder (folder)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $applied = array_flip(
            DB::run("SELECT folder FROM {$tMig} ORDER BY folder")->fetchAll(PDO::FETCH_COLUMN)
        );

        $root = defined('OSTRAKON_ROOT') ? OSTRAKON_ROOT : dirname(__DIR__);
        $folders = glob($root . '/migrations/[0-9]*', GLOB_ONLYDIR) ?: [];
        usort($folders, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

        $appliedCount = 0;
        $skipped      = 0;
        $failed       = null;

        foreach ($folders as $folder) {
            $name = basename($folder);

            if (isset($applied[$name])) {
                $lines[] = "[{$name}] skip (already applied)";
                $skipped++;
                continue;
            }

            $lines[] = "[{$name}] running...";

            $files = array_merge(glob($folder . '/*.sql') ?: [], glob($folder . '/*.php') ?: []);
            sort($files);

            if ($files === []) {
                $lines[] = "[{$name}] no files — skip";
                continue;
            }

            $ok = true;
            foreach ($files as $file) {
                $fname = basename($file);
                $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                try {
                    if ($ext === 'sql') {
                        self::execSql((string) file_get_contents($file), $prefix);
                    } else { // php
                        include $file;
                    }
                    $lines[] = "  ├─ {$fname} ... OK";
                } catch (Throwable $e) {
                    $lines[] = "  └─ {$fname} ... ERROR: " . $e->getMessage();
                    Logger::error('Migrator: error in migration file', $e, ['folder' => $name, 'file' => $fname]);
                    $ok = false;
                    $failed = $name;
                    break;
                }
            }

            if (!$ok) {
                $lines[] = "[{$name}] FAILED — process stopped";
                break;
            }

            DB::run("INSERT INTO {$tMig} (folder) VALUES (?)", [$name]);
            $lines[] = "[{$name}] success — marked as applied";
            $appliedCount++;
        }

        Logger::info('Migrator: finished', ['applied' => $appliedCount, 'skipped' => $skipped, 'failed' => $failed]);

        return ['applied' => $appliedCount, 'skipped' => $skipped, 'failed' => $failed, 'lines' => $lines];
    }

    /** Run a SQL file: substitute {prefix}, strip comments, execute statement by statement. */
    private static function execSql(string $sql, string $prefix): void
    {
        $sql = str_replace('{prefix}', $prefix, $sql);
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql; // line comments
        $pdo = DB::pdo();
        foreach (array_filter(array_map('trim', explode(';', $sql)), static fn(string $s): bool => $s !== '') as $stmt) {
            $pdo->exec($stmt);
        }
    }
}
