<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * DevAuth — access gate for the dev endpoints (log.php, inspect.php).
 *
 * Authorized by DEV_TOKEN — a dedicated secret, SEPARATE from SUPERADMIN_TOKEN so leaking one
 * never exposes the other. It is EMPTY by default, which DISABLES the endpoints entirely (they
 * answer 404). When set, the first request with ?token=... sets a cookie and subsequent requests
 * are authorized by that cookie — no need to carry the token in the URL. CLI is always allowed
 * (server access is protection enough).
 *
 * For development only. These endpoints should be deleted in production.
 */
final class DevAuth
{
    private const COOKIE = 'ostrakon_dev';

    public static function gate(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        // Dev endpoints have their OWN secret (DEV_TOKEN), separate from SUPERADMIN_TOKEN so that
        // leaking one never exposes the other. It is EMPTY by default → the tools are disabled and
        // answer 404 (as if the file weren't there). Set DEV_TOKEN in config/bot.php only while you
        // actually need log.php / inspect.php, then clear it again (or delete the files).
        $expected = (string) Config::value('bot', 'DEV_TOKEN', '');
        if ($expected === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not Found\n";
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');

        $fromGet = (string) ($_GET['token'] ?? '');
        $fromCk  = (string) ($_COOKIE[self::COOKIE] ?? '');

        if (hash_equals($expected, $fromGet)) {
            // Remember the token in a cookie for the browser session.
            setcookie(self::COOKIE, $expected, [
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => self::isHttps(),
            ]);
            return;
        }

        if (hash_equals($expected, $fromCk)) {
            return;
        }

        http_response_code(403);
        echo "Forbidden. Open once with ?token=YOUR_DEV_TOKEN — the cookie handles the rest.\n";
        exit;
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }
}
