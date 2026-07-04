<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Bot — the single access point to the Telegram Bot API.
 *
 * All Telegram calls go through Bot::call($method, $params).
 * - Nested arrays (reply_markup etc.) are JSON-encoded automatically.
 * - On error (network / ok=false) we log it and return null — the caller checks the
 *   result, the webhook never crashes.
 *
 * Returns the `result` field of the Telegram response when ok=true, otherwise null.
 */
final class Bot
{
    private const API = 'https://api.telegram.org/bot';
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 5;

    /** Code and description of the last error (for the caller to inspect). */
    private static ?int $lastErrorCode = null;
    private static ?string $lastErrorDescription = null;
    /** New chat_id from a "group upgraded to supergroup" error, if any. */
    private static ?int $migrateToChatId = null;

    /**
     * @param array<string, mixed> $params
     * @param bool $quiet Log an ok=false Telegram error at DEBUG instead of ERROR (for calls
     *                    whose failure is expected — e.g. membership checks on groups the bot
     *                    may have left).
     * @return mixed The Telegram response's result field, or null on error.
     */
    public static function call(string $method, array $params = [], bool $quiet = false): mixed
    {
        self::$lastErrorCode = null;
        self::$lastErrorDescription = null;
        self::$migrateToChatId = null;

        $token = (string) Config::value('bot', 'BOT_TOKEN', '');
        if ($token === '') {
            self::$lastErrorDescription = 'BOT_TOKEN not configured';
            Logger::error('Bot::call — BOT_TOKEN not set in config/bot.php');
            return null;
        }

        // Telegram expects nested structures as JSON strings.
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $params[$key] = $value ? 'true' : 'false';
            }
        }

        $url = self::API . $token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            self::$lastErrorDescription = curl_error($ch);
            Logger::error("Bot::call({$method}) — curl: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $resp = json_decode((string) $raw, true);
        if (!is_array($resp)) {
            self::$lastErrorDescription = 'invalid JSON response';
            Logger::error("Bot::call({$method}) — invalid JSON in response: " . substr((string) $raw, 0, 500));
            return null;
        }

        if (empty($resp['ok'])) {
            self::$lastErrorCode = isset($resp['error_code']) ? (int) $resp['error_code'] : null;
            self::$lastErrorDescription = (string) ($resp['description'] ?? '');
            if (isset($resp['parameters']['migrate_to_chat_id'])) {
                self::$migrateToChatId = (int) $resp['parameters']['migrate_to_chat_id'];
            }
            $code = $resp['error_code'] ?? '?';
            $desc = $resp['description'] ?? 'no description';
            $line = "Bot::call({$method}) — Telegram returned ok=false [{$code}]: {$desc}";
            $quiet ? Logger::debug($line) : Logger::error($line);
            return null;
        }

        return $resp['result'] ?? null;
    }

    /** Error code of the last call (Telegram error_code) or null. */
    public static function lastErrorCode(): ?int
    {
        return self::$lastErrorCode;
    }

    /** Error description of the last call (Telegram description) or null. */
    public static function lastErrorDescription(): ?string
    {
        return self::$lastErrorDescription;
    }

    /** New chat_id if the last error was "group upgraded to a supergroup", else null. */
    public static function migrateToChatId(): ?int
    {
        return self::$migrateToChatId;
    }

    /**
     * Whether the last error indicates the target message is gone (deleted / not
     * editable) — the typical sign that a vote message was deleted manually.
     */
    public static function messageGone(): bool
    {
        if (self::$lastErrorCode !== 400) {
            return false;
        }
        $d = strtolower((string) self::$lastErrorDescription);
        return str_contains($d, 'message to edit not found')
            || str_contains($d, 'message to delete not found')
            || str_contains($d, "message can't be edited")
            || str_contains($d, 'message identifier is not specified');
    }
}
