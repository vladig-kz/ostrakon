<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Logger — writes to a file by severity level (logs/app.log), NOT to output.
 *
 * The webhook must always return HTTP 200, so errors must not be emitted in the
 * response — only to the log. Log timestamps are UTC (gmdate), like everything else.
 *
 * Levels (descending severity):
 *   FATAL(60) > ERROR(50) > WARNING(40) > INFO(30) > DEBUG(20) > TRACE(10)
 *
 * The threshold is set in config/bot.php → 'LOG_LEVEL' (string: 'trace'..'fatal').
 * Only messages at or above the threshold are written. Use 'trace'/'debug' while
 * debugging; 'warning' or 'error' make sense in production.
 */
final class Logger
{
    public const FATAL   = 60;
    public const ERROR   = 50;
    public const WARNING = 40;
    public const INFO    = 30;
    public const DEBUG   = 20;
    public const TRACE   = 10;

    /** Numeric level → log label. */
    private const NAMES = [
        self::FATAL   => 'FATAL',
        self::ERROR   => 'ERROR',
        self::WARNING => 'WARN',
        self::INFO    => 'INFO',
        self::DEBUG   => 'DEBUG',
        self::TRACE   => 'TRACE',
    ];

    /** Level name from config → numeric level. */
    private const BY_NAME = [
        'fatal'   => self::FATAL,
        'error'   => self::ERROR,
        'warning' => self::WARNING,
        'warn'    => self::WARNING,
        'info'    => self::INFO,
        'debug'   => self::DEBUG,
        'trace'   => self::TRACE,
    ];

    private const DEFAULT_LEVEL = self::DEBUG;

    /** Cached threshold for the request (null = not yet read from config). */
    private static ?int $threshold = null;

    public static function fatal(string $message, ?Throwable $e = null, array $context = []): void
    {
        self::log(self::FATAL, $message, $e, $context);
    }

    public static function error(string $message, ?Throwable $e = null, array $context = []): void
    {
        self::log(self::ERROR, $message, $e, $context);
    }

    public static function warning(string $message, ?Throwable $e = null, array $context = []): void
    {
        self::log(self::WARNING, $message, $e, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, null, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, null, $context);
    }

    public static function trace(string $message, array $context = []): void
    {
        self::log(self::TRACE, $message, null, $context);
    }

    /**
     * Override the threshold programmatically (e.g. in CLI/cron). Pass a level name
     * ('trace'..'fatal') or null to fall back to reading it from config.
     */
    public static function setLevel(?string $name): void
    {
        if ($name === null) {
            self::$threshold = null;
            return;
        }
        self::$threshold = self::BY_NAME[strtolower($name)] ?? self::DEFAULT_LEVEL;
    }

    /**
     * @param array<string, mixed> $context Arbitrary debugging data (→ JSON).
     */
    public static function log(int $level, string $message, ?Throwable $e = null, array $context = []): void
    {
        if ($level < self::threshold()) {
            return;
        }

        // The label is padded to 5 chars so log lines don't "jump".
        $label = str_pad(self::NAMES[$level] ?? (string) $level, 5);
        $line  = gmdate('Y-m-d H:i:s') . " [{$label}] " . $message;

        if ($context !== []) {
            $line .= ' | ctx=' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($e !== null) {
            $line .= ' | ' . get_class($e) . ': ' . $e->getMessage()
                   . ' @ ' . $e->getFile() . ':' . $e->getLine();
            // Stack trace only at the most verbose levels, to avoid bloating the log.
            if (self::threshold() <= self::DEBUG) {
                $line .= PHP_EOL . $e->getTraceAsString();
            }
        }

        $line .= PHP_EOL;

        @file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX);
    }

    private static function threshold(): int
    {
        if (self::$threshold === null) {
            $name = 'debug';
            try {
                $name = strtolower((string) Config::value('bot', 'LOG_LEVEL', 'debug'));
            } catch (Throwable) {
                // Config unavailable (e.g. an early error) — log verbosely.
            }
            self::$threshold = self::BY_NAME[$name] ?? self::DEFAULT_LEVEL;
        }
        return self::$threshold;
    }

    private static function file(): string
    {
        $root = defined('OSTRAKON_ROOT') ? OSTRAKON_ROOT : dirname(__DIR__);
        $dir = $root . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/app.log';
    }
}
