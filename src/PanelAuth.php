<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * PanelAuth — admin-panel authentication via the Telegram Login Widget.
 *
 * No passwords: login only with data signed by Telegram (HMAC-SHA256 keyed by
 * SHA256(BOT_TOKEN)). After verification — a PHP session. Every action that changes a
 * group's data separately re-checks that the logged-in user administers THAT group (done
 * at the controller level, not here). CSRF — for POST forms.
 */
final class PanelAuth
{
    private const SESSION_KEY = 'admin_user';
    private const AUTH_TTL    = 900; // accept signed login data for 15 min — limits replay of the signed login URL leaked via access logs / browser history

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $base = Panel::basePath();
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $base !== '' ? $base : '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('ostrakon_panel');
        session_start();
    }

    /**
     * The currently logged-in user, or null.
     * @return array{id:int, name:string, username:?string}|null
     */
    public static function user(): ?array
    {
        $u = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($u) ? $u : null;
    }

    /** Handle the Telegram Login Widget redirect (GET params with a signature). */
    public static function handleLogin(): void
    {
        $data = $_GET;
        if (!self::verify($data)) {
            Logger::warning('PanelAuth: Login Widget signature check failed', null, ['id' => $data['id'] ?? null]);
            Panel::error(403, Lang::get('panel_auth_failed', Panel::lang()));
            return;
        }

        $_SESSION[self::SESSION_KEY] = [
            'id'       => (int) $data['id'],
            'name'     => trim((string) ($data['first_name'] ?? '') . ' ' . (string) ($data['last_name'] ?? '')),
            'username' => isset($data['username']) ? (string) $data['username'] : null,
        ];
        session_regenerate_id(true);
        Logger::info('PanelAuth: panel login', ['id' => (int) $data['id']]);

        // Sync the UI language between the on-site cookie and the stored (DB) value: newest wins.
        Panel::reconcileLoginLanguage((int) $data['id']);

        // Return to the page the user originally requested (if any); the destination re-checks
        // that they administer that group, so a crafted link to a foreign group yields 403.
        $next = Panel::takeAfterLogin();
        self::redirect(Panel::baseUrl() . '/' . ($next !== '' ? $next : 'admin'));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::redirect(Panel::baseUrl() . '/admin');
    }

    /**
     * Verify the Telegram Login Widget data signature.
     * @param array<string, mixed> $data
     */
    private static function verify(array $data): bool
    {
        $hash = (string) ($data['hash'] ?? '');
        if ($hash === '' || !isset($data['auth_date'], $data['id'])) {
            return false;
        }
        // Freshness of the signed Login Widget data. "Now" comes from the DB server (the app's
        // single source of time) so this doesn't break if the PHP host's clock has drifted.
        if (DB::nowUnix() - (int) $data['auth_date'] > self::AUTH_TTL) {
            return false;
        }
        $token = (string) Config::value('bot', 'BOT_TOKEN', '');
        if ($token === '') {
            return false;
        }

        unset($data['hash']);
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $checkString = implode("\n", $pairs);
        $secretKey   = hash('sha256', $token, true);
        $calc        = hash_hmac('sha256', $checkString, $secretKey);

        return hash_equals($calc, $hash);
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url);
        echo 'Redirecting…';
    }

    // ------------------------------------------------------------------
    // CSRF (for POST actions: participants, settings, …)
    // ------------------------------------------------------------------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function csrfCheck(?string $token): bool
    {
        return is_string($token) && $token !== ''
            && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }
}
