<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * DB — a thin PDO wrapper (singleton).
 *
 * - One connection per request (lazy connect).
 * - DB::table('votes') prepends DB_TABLE_PREFIX to the table name.
 * - On connect the DB session is switched to UTC (SET time_zone='+00:00') so that
 *   NOW()/DATE_SUB() etc. always work in UTC — regardless of the hosting server's
 *   timezone ("single source of time").
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static ?string $prefix = null;

    /** DB-server epoch captured at connect + the monotonic clock reading at that moment. */
    private static int $dbEpochAtConnect = 0;
    private static float $monoAtConnect = 0.0;

    /** Get (and, if needed, open) the PDO connection. */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::get('db');

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['DB_HOST'] ?? 'localhost',
                (int) ($cfg['DB_PORT'] ?? 3306),
                $cfg['DB_NAME'] ?? '',
                $cfg['DB_CHARSET'] ?? 'utf8mb4'
            );

            self::$pdo = new PDO(
                $dsn,
                $cfg['DB_USER'] ?? '',
                $cfg['DB_PASS'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            // All time calculations on the DB side run in UTC.
            self::$pdo->exec("SET time_zone = '+00:00'");

            // Anchor the app clock to the DB server once, at connect (the single source of time).
            // nowUnix() then advances this by the monotonic elapsed time — no repeated queries,
            // and it stays correct even in a long-lived worker (which re-anchors every run).
            self::$dbEpochAtConnect = (int) self::$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
            self::$monoAtConnect    = (float) hrtime(true) / 1_000_000_000;
        }

        return self::$pdo;
    }

    /** Prefixed table name: DB::table('votes') → 'vb_votes'. */
    public static function table(string $name): string
    {
        if (self::$prefix === null) {
            self::$prefix = (string) Config::value('db', 'DB_TABLE_PREFIX', '');
        }
        return self::$prefix . $name;
    }

    /**
     * Prepare and execute a query, return the PDOStatement.
     *
     * @param array<int|string, mixed> $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * First result row (or null).
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * All result rows.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * Scalar value from the first column of the first row (or null).
     *
     * @param array<int|string, mixed> $params
     */
    public static function fetchColumn(string $sql, array $params = []): mixed
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }

    /**
     * Current UNIX timestamp on the DB server's clock — the app's single source of time. Use
     * this (not PHP's time()) whenever a value must be comparable with DB timestamps (written
     * via NOW() in the UTC session).
     *
     * The DB clock is read once at connect and then advanced by the monotonic elapsed time, so
     * there's no per-call query: within a normal request the elapsed part is ~0 (effectively a
     * constant), and in a long-lived worker it keeps ticking (and re-anchors each run).
     */
    public static function nowUnix(): int
    {
        self::pdo(); // ensure connected → anchor captured
        $elapsed = ((float) hrtime(true) / 1_000_000_000) - self::$monoAtConnect;
        return self::$dbEpochAtConnect + (int) $elapsed;
    }

    public static function begin(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::pdo()->commit();
    }

    public static function rollBack(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
