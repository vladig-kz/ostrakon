<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Handler — routing of incoming Telegram updates.
 *
 * It recognizes the update type and sub-case, extracts the needed fields, logs, and
 * delegates to the managers (GroupManager / VoteManager / ScoreManager).
 *
 * Webhook allowed_updates: message, callback_query, chat_member, my_chat_member.
 */
final class Handler
{
    public static function handle(array $update): void
    {
        if (isset($update['message'])) {
            self::onMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            self::onCallbackQuery($update['callback_query']);
        } elseif (isset($update['chat_member'])) {
            self::onChatMember($update['chat_member']);
        } elseif (isset($update['my_chat_member'])) {
            self::onMyChatMember($update['my_chat_member']);
        } else {
            Logger::trace('Handler: unsupported update type', ['keys' => array_keys($update)]);
        }
    }

    // =====================================================================
    // message
    // =====================================================================
    private static function onMessage(array $message): void
    {
        // Basic group upgraded to a supergroup: chat_id changes — migrate our data.
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if (isset($message['migrate_to_chat_id'])) {
            GroupManager::migrateChat($chatId, (int) $message['migrate_to_chat_id']);
            return;
        }
        if (isset($message['migrate_from_chat_id'])) {
            GroupManager::migrateChat((int) $message['migrate_from_chat_id'], $chatId);
            return;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text     = (string) ($message['text'] ?? $message['caption'] ?? '');

        // --- Private chat with the bot (onboarding) ---
        if ($chatType === 'private') {
            // An inbound private message proves the dialog is open — remember it so we may DM later.
            GroupManager::markDm((int) ($message['from']['id'] ?? 0));
            $cmd = self::parseCommand($text);
            Logger::debug('Handler: private message', [
                'from' => $message['from']['id'] ?? null,
                'cmd'  => $cmd['cmd'] ?? null,
            ]);

            if (($cmd['cmd'] ?? null) === 'start') {
                GroupManager::startDialog($message['from'] ?? []);
                return;
            }
            if (($cmd['cmd'] ?? null) === 'language') {
                GroupManager::promptLanguage((int) ($message['from']['id'] ?? 0), false);
                return;
            }
            if (($cmd['cmd'] ?? null) === 'groups') {
                GroupManager::showGroups($message['from'] ?? []);
                return;
            }
            if (($cmd['cmd'] ?? null) === 'help') {
                GroupManager::sendHelp((int) ($message['from']['id'] ?? 0));
                return;
            }
            // A reply to a notification → a moderation command (forceban/cancelban/protect/unban).
            if (isset($message['reply_to_message']) && Notifier::handleReply($message)) {
                return;
            }
            // Other private messages are ignored for now.
            return;
        }

        // --- Group / supergroup ---
        if ($chatType !== 'group' && $chatType !== 'supergroup') {
            Logger::trace('Handler: message from an unsupported chat', ['chat_type' => $chatType]);
            return;
        }

        // In a group the bot reacts ONLY to vote initiation (reply + bot mention).
        // All admin actions (protect/unban/settings) go through the web panel and DMs, not
        // the group — to avoid clutter and conflicts with other bots. An ongoing vote is
        // controlled by the admin via buttons (their "for" = instant ban, "against" = decline).
        if (self::isVoteTrigger($message)) {
            Logger::debug('Handler: vote trigger (reply + bot mention)', [
                'chat_id'   => $chatId,
                'initiator' => $message['from']['id'] ?? null,
                'target'    => $message['reply_to_message']['from']['id'] ?? null,
            ]);
            VoteManager::initiate($message);
            return;
        }

        // The bot was mentioned but this isn't a valid trigger (not a reply, or the message has
        // text besides the bare mention — e.g. someone explaining how to use the bot). Explain
        // how to vote instead of silently doing nothing (or, worse, voting the wrong person out).
        // Commands like /start@bot are skipped.
        $rawText = ltrim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if (self::mentionsBot($message) && !str_starts_with($rawText, '/')) {
            Logger::debug('Handler: bot mentioned without a valid trigger → how-to hint', ['chat_id' => $chatId]);
            VoteManager::hintHowToVote($chatId, VoteManager::threadOf($message));
            return;
        }

        // Ordinary member message. In full mode — record metadata for the score.
        $group = GroupManager::getGroup($chatId);
        if ($group !== null && ($group['mode'] ?? 'light') === 'full') {
            ScoreManager::recordMessage($message, $group);
        } else {
            Logger::trace('Handler: ordinary group message (not counted)', [
                'chat_id' => $chatId,
                'from'    => $message['from']['id'] ?? null,
            ]);
        }
    }

    // =====================================================================
    // callback_query ("for"/"against" buttons)
    // =====================================================================
    private static function onCallbackQuery(array $cb): void
    {
        $data = (string) ($cb['data'] ?? '');
        Logger::debug('Handler: callback_query', [
            'from' => $cb['from']['id'] ?? null,
            'data' => $data,
        ]);

        // Voting buttons (v:f:<id> / v:a:<id>) — VoteManager clears the spinner itself.
        if (str_starts_with($data, 'v:')) {
            VoteManager::castVote($cb);
            return;
        }

        // Language picker (lang:<code>) from /start or /language.
        if (str_starts_with($data, 'lang:')) {
            GroupManager::setLanguageFromCallback($cb);
            return;
        }

        // Other callbacks — just clear the spinner.
        if (isset($cb['id'])) {
            Bot::call('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        }
    }

    // =====================================================================
    // chat_member (member status change)
    // =====================================================================
    private static function onChatMember(array $cm): void
    {
        $chatId    = (int) ($cm['chat']['id'] ?? 0);
        $user      = $cm['new_chat_member']['user'] ?? [];
        $oldStatus = (string) ($cm['old_chat_member']['status'] ?? '');
        $newStatus = (string) ($cm['new_chat_member']['status'] ?? '');

        Logger::debug('Handler: chat_member', [
            'chat_id' => $chatId,
            'user'    => $user['id'] ?? null,
            'old'     => $oldStatus,
            'new'     => $newStatus,
        ]);

        $present = static fn(string $s): bool => in_array($s, ['member', 'restricted', 'administrator', 'creator'], true);
        $absent  = static fn(string $s): bool => in_array($s, ['left', 'kicked', ''], true);

        if ($newStatus === 'kicked') {
            // Member was banned (including manually by an admin directly in Telegram).
            $userId = (int) ($user['id'] ?? 0);
            if ($userId !== 0) {
                GroupManager::onMemberBanned($chatId, $userId);
                VoteManager::onManualBan($chatId, $userId);
            }
            return;
        }

        if ($present($newStatus) && $absent($oldStatus)) {
            // New join (record the participant + re-entry check).
            GroupManager::onJoin($chatId, $user);
            return;
        }

        if ($newStatus === 'left') {
            // Member left on their own.
            GroupManager::onLeave($chatId, $user);
            return;
        }

        Logger::trace('Handler: chat_member no-op', ['old' => $oldStatus, 'new' => $newStatus]);
    }

    // =====================================================================
    // my_chat_member (the bot's own status changed)
    // =====================================================================
    private static function onMyChatMember(array $cm): void
    {
        $chatId    = (int) ($cm['chat']['id'] ?? 0);
        $oldStatus = (string) ($cm['old_chat_member']['status'] ?? '');
        $newStatus = (string) ($cm['new_chat_member']['status'] ?? '');
        $byUser    = $cm['from']['id'] ?? null;

        Logger::debug('Handler: my_chat_member (bot status changed)', [
            'chat_id' => $chatId,
            'old'     => $oldStatus,
            'new'     => $newStatus,
            'by'      => $byUser,
        ]);

        $wasAbsent = in_array($oldStatus, ['left', 'kicked', ''], true);
        $isPresent = in_array($newStatus, ['member', 'administrator'], true);

        // Onboard only on a REAL add (was absent → became member/administrator).
        // A member↔administrator rights change does not trigger onboarding.
        if ($isPresent && $wasAbsent) {
            GroupManager::onBotAdded($chatId, $cm);
            return;
        }

        if (in_array($newStatus, ['left', 'kicked'], true)) {
            GroupManager::onBotRemoved($chatId);
            return;
        }

        // Still present, but the bot's rights changed (promoted/demoted, ban right granted/revoked).
        if ($isPresent) {
            GroupManager::onBotRightsChanged($chatId, (int) ($cm['from']['id'] ?? 0));
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Parse a command from message text.
     * "/protect@MyBot foo" → ['cmd' => 'protect', 'args' => ['foo']].
     *
     * @return array{cmd: string, args: array<int, string>}|null
     */
    private static function parseCommand(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        $parts = preg_split('/\s+/', $text) ?: [];
        $first = (string) array_shift($parts);

        $cmd = ltrim($first, '/');
        if (($at = strpos($cmd, '@')) !== false) {   // strip @botname
            $cmd = substr($cmd, 0, $at);
        }

        return ['cmd' => strtolower($cmd), 'args' => array_values($parts)];
    }

    /**
     * Whether the message is a vote trigger: a reply to someone's message whose text is NOTHING
     * BUT the bot mention. Requiring the bare mention (not just "contains @bot") prevents an
     * explanatory sentence — "reply and write @bot" — from silently putting the replied-to user
     * up for a vote.
     */
    private static function isVoteTrigger(array $message): bool
    {
        if (!isset($message['reply_to_message'])) {
            return false;
        }
        $username = strtolower((string) Config::value('bot', 'BOT_USERNAME', ''));
        if ($username === '') {
            return false;
        }
        $text = strtolower(trim((string) ($message['text'] ?? $message['caption'] ?? '')));
        return $text === '@' . $username;
    }

    /** Whether the message text/caption mentions the bot anywhere. */
    private static function mentionsBot(array $message): bool
    {
        $username = strtolower((string) Config::value('bot', 'BOT_USERNAME', ''));
        if ($username === '') {
            return false;
        }
        $text = strtolower((string) ($message['text'] ?? $message['caption'] ?? ''));
        return str_contains($text, '@' . $username);
    }
}
