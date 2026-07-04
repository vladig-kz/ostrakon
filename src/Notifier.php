<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Notifier — personal DM notifications to admins who opted in (per group, per event).
 *
 * Recipients are the group's participants whose matching notify_* flag is set. Each DM is sent
 * in the recipient's own language (users.lang, else the group's language). A bot can't message
 * a user who never opened a chat with it, so a failed send simply no-ops for that recipient.
 */
final class Notifier
{
    /** An interactive vote just went live. */
    public static function voteStarted(int $chatId, int $initiatorId, int $targetId, int $voteId): void
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $g   = self::groupTitle($group, $chatId);
        $ini = self::userLabel($chatId, $initiatorId);
        $tgt = self::userLabel($chatId, $targetId);
        self::deliver($chatId, 'notify_votes', (string) ($group['lang'] ?? 'ru'),
            static fn(string $lang): string => Lang::get('notify_msg_vote_started', $lang,
                ['group' => $g, 'initiator' => $ini, 'target' => $tgt]),
            ['kind' => 'vote', 'target_id' => $targetId, 'vote_id' => $voteId, 'hint' => 'notify_actions_vote_hint']);
    }

    /** A vote finished: banned | declined | expired | cancelled. */
    public static function voteFinished(int $chatId, int $targetId, string $outcome): void
    {
        $key = [
            'banned'    => 'notify_msg_ban_banned',
            'declined'  => 'notify_msg_ban_declined',
            'expired'   => 'notify_msg_ban_expired',
            'cancelled' => 'notify_msg_ban_cancelled',
        ][$outcome] ?? null;
        if ($key === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $g   = self::groupTitle($group, $chatId);
        $tgt = self::userLabel($chatId, $targetId);
        // Only an actual ban leaves something to undo (unban / protect) via a reply.
        $action = $outcome === 'banned'
            ? ['kind' => 'ban', 'target_id' => $targetId, 'vote_id' => null, 'hint' => 'notify_actions_ban_hint']
            : null;
        self::deliver($chatId, 'notify_bans', (string) ($group['lang'] ?? 'ru'),
            static fn(string $lang): string => Lang::get($key, $lang, ['group' => $g, 'target' => $tgt]),
            $action);
    }

    /** A member became ($became = true) or stopped being ($became = false) an elder. */
    public static function elderChanged(int $chatId, int $userId, bool $became): void
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $g   = self::groupTitle($group, $chatId);
        $u   = self::userLabel($chatId, $userId);
        $key = $became ? 'notify_msg_elder_became' : 'notify_msg_elder_lost';
        self::deliver($chatId, 'notify_elders', (string) ($group['lang'] ?? 'ru'),
            static fn(string $lang): string => Lang::get($key, $lang, ['group' => $g, 'user' => $u]));
    }

    /**
     * DM every participant of the group whose $flagColumn = 1, each in their own language.
     * $flagColumn is an internal constant (never user input). If $action is given, a hint line
     * is appended and the sent message is recorded so the admin can reply with a command.
     *
     * @param array{kind:string, target_id:int, vote_id:?int, hint:string}|null $action
     */
    private static function deliver(int $chatId, string $flagColumn, string $groupLang, callable $textFor, ?array $action = null): void
    {
        $rows = DB::fetchAll(
            "SELECT user_id FROM " . DB::table('participants') . " WHERE chat_id = ? AND {$flagColumn} = 1",
            [$chatId]
        );
        foreach ($rows as $r) {
            $uid  = (int) $r['user_id'];
            $lang = GroupManager::getUserLang($uid) ?: $groupLang;
            $text = $textFor($lang);
            if ($action !== null) {
                $text .= "\n\n" . Lang::get($action['hint'], $lang);
            }
            $res = Bot::call('sendMessage', ['chat_id' => $uid, 'text' => $text]);
            if ($action !== null && is_array($res) && isset($res['message_id'])) {
                DB::run(
                    "INSERT INTO " . DB::table('notify_actions')
                    . " (user_id, message_id, chat_id, target_id, vote_id, kind, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id)",
                    [$uid, (int) $res['message_id'], $chatId, $action['target_id'], $action['vote_id'], $action['kind']]
                );
            }
        }
    }

    /**
     * A private message that replies to a notification: run the moderation command it carries.
     * Returns true if the reply was a recognized notification action (consumed), false otherwise.
     *
     * @param array<string, mixed> $message the incoming private message
     */
    public static function handleReply(array $message): bool
    {
        $userId  = (int) ($message['from']['id'] ?? 0);
        $replyId = (int) ($message['reply_to_message']['message_id'] ?? 0);
        if ($userId === 0 || $replyId === 0) {
            return false;
        }
        $ctx = DB::fetch(
            "SELECT chat_id, target_id, vote_id, kind FROM " . DB::table('notify_actions')
            . " WHERE user_id = ? AND message_id = ?",
            [$userId, $replyId]
        );
        if ($ctx === null) {
            return false; // not a reply to one of our notifications
        }

        $chatId   = (int) $ctx['chat_id'];
        $targetId = (int) $ctx['target_id'];
        $voteId   = (int) ($ctx['vote_id'] ?? 0);
        $kind     = (string) $ctx['kind'];
        $lang     = GroupManager::getUserLang($userId) ?: 'ru';

        // The word (with or without a leading slash / trailing @bot).
        $word = strtolower(trim((string) ($message['text'] ?? '')));
        $word = ltrim($word, '/');
        $word = (string) (preg_split('/[\s@]+/', $word)[0] ?? '');

        // Still an admin of that group?
        if (!GroupManager::isAdmin($chatId, $userId)) {
            self::reply($userId, Lang::get('notify_cmd_not_admin', $lang));
            return true;
        }

        $allowed = $kind === 'vote' ? ['forceban', 'cancelban', 'protect'] : ['unban', 'protect'];
        if (!in_array($word, $allowed, true)) {
            self::reply($userId, Lang::get('notify_cmd_unknown', $lang, ['cmds' => implode(', ', $allowed)]));
            return true;
        }

        if ($kind === 'vote' && $word === 'forceban') {
            $ok = VoteManager::finalizeByAdmin($voteId, 'banned');
            self::reply($userId, Lang::get($ok ? 'notify_cmd_done_forceban' : 'notify_cmd_vote_gone', $lang));
        } elseif ($kind === 'vote' && $word === 'cancelban') {
            $ok = VoteManager::finalizeByAdmin($voteId, 'cancelled');
            self::reply($userId, Lang::get($ok ? 'notify_cmd_done_cancelban' : 'notify_cmd_vote_gone', $lang));
        } elseif ($word === 'protect') {
            if ($kind === 'vote') {
                VoteManager::finalizeByAdmin($voteId, 'cancelled'); // cancel the running vote, if still active
            }
            GroupManager::setProtectionById($chatId, $targetId, true);
            self::reply($userId, Lang::get('notify_cmd_done_protect', $lang));
        } else { // unban (ban kind)
            GroupManager::unbanById($chatId, $targetId);
            self::reply($userId, Lang::get('notify_cmd_done_unban', $lang));
        }

        Logger::info('Notifier: DM moderation command', ['user' => $userId, 'chat' => $chatId, 'kind' => $kind, 'cmd' => $word]);
        return true;
    }

    private static function reply(int $userId, string $text): void
    {
        Bot::call('sendMessage', ['chat_id' => $userId, 'text' => $text]);
    }

    /** Cron (data_ttl): drop notify_actions context rows older than $days days. Returns count. */
    public static function purgeOldActions(int $days): int
    {
        $stmt = DB::run(
            "DELETE FROM " . DB::table('notify_actions') . " WHERE created_at < NOW() - INTERVAL ? DAY",
            [$days]
        );
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $group */
    private static function groupTitle(array $group, int $chatId): string
    {
        $t = (string) ($group['title'] ?? '');
        return $t !== '' ? $t : ('#' . $chatId);
    }

    /** "@username" if we have it, otherwise "id <N>". */
    private static function userLabel(int $chatId, int $userId): string
    {
        $u = DB::fetchColumn(
            "SELECT username FROM " . DB::table('participants') . " WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return (is_string($u) && $u !== '') ? '@' . $u : ('id ' . $userId);
    }
}
