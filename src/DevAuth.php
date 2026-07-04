<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * DevAuth — access gate for the dev endpoints (log.php, inspect.php).
 *
 * Authorized by SUPERADMIN_TOKEN: the first request with ?token=... sets a cookie, and
 * subsequent requests are authorized by that cookie — no need to carry the token in the
 * URL. CLI is always allowed (server access is protection enough).
 *
 * For development only. These endpoints can be omitted in production.
 */
final class DevAuth
{
    private const COOKIE = 'ostrakon_dev';

    public static function gate(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');

        $expected = (string) Config::value('bot', 'SUPERADMIN_TOKEN', '');
        $fromGet  = (string) ($_GET['token'] ?? '');
        $fromCk   = (string) ($_COOKIE[self::COOKIE] ?? '');

        if ($expected !== '' && hash_equals($expected, $fromGet)) {
            // Remember the token in a cookie for the browser session.
            setcookie(self::COOKIE, $expected, [
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => self::isHttps(),
            ]);
            return;
        }

        if ($expected !== '' && hash_equals($expected, $fromCk)) {
            return;
        }

        http_response_code(403);
        echo "Forbidden. Open once with ?token=YOUR_SUPERADMIN_TOKEN — the cookie handles the rest.\n";
        exit;
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }
}
