<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Ostrakon — shared bootstrap.
 * Included first by every entry point (webhook.php, cron.php, index.php, …).
 *
 * - Defines the project root.
 * - Registers a simple autoloader for classes in src/
 *   (class name = file name, no namespace: DB → src/DB.php).
 */

define('OSTRAKON_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
