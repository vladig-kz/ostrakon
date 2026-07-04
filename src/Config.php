<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Config — loads configuration files from config/.
 *
 * Each config is a PHP file returning an associative array
 * (config/bot.php, config/db.php, config/defaults.php).
 * Loaded arrays are cached for the duration of the request.
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Return the whole config by file name (without extension).
     *
     * @return array<string, mixed>
     */
    public static function get(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $file = self::dir() . '/' . $name . '.php';
            if (!is_file($file)) {
                throw new RuntimeException("Config file not found: {$name}.php");
            }
            $data = require $file;
            if (!is_array($data)) {
                throw new RuntimeException("Config file {$name}.php must return an array");
            }
            self::$cache[$name] = $data;
        }
        return self::$cache[$name];
    }

    /**
     * Return a single config value with a default.
     */
    public static function value(string $name, string $key, mixed $default = null): mixed
    {
        // value() with a default tolerates a missing config file:
        // no file or no key → default. Strict checking lives in get() only.
        try {
            $cfg = self::get($name);
        } catch (RuntimeException) {
            return $default;
        }
        return $cfg[$key] ?? $default;
    }

    private static function dir(): string
    {
        $root = defined('OSTRAKON_ROOT') ? OSTRAKON_ROOT : dirname(__DIR__);
        return $root . '/config';
    }
}
