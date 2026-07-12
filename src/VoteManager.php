<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * VoteManager — the voting logic.
 *
 * Covers the whole flow: initiating a vote, tallying votes, thresholds
 * (readonly/ban/decline), finalization, admin decisions, and cron timeouts.
 *
 * Messages are sent without parse_mode (plain text): Telegram makes @username clickable
 * on its own, display names need no escaping, and we deliberately don't build links to
 * participants.
 */
final class VoteManager
{
    /**
     * Initiate a vote from a trigger message (reply to the target + bot @mention).
     *
     * @param array<string, mixed> $message the message object from the update
     */
    public static function initiate(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $reply  = $message['reply_to_message'] ?? null;
        if ($chatId === 0 || !is_array($reply)) {
            return;
        }

        $group = GroupManager::ensureGroup($chatId);
        $lang  = (string) ($group['lang'] ?? 'ru');

        // Forum topic (if any) the trigger was written in — everything about this vote is posted
        // back into the SAME topic, not "General".
        $threadId = self::threadOf($message);

        $initiator = $message['from'] ?? [];
        $target    = $reply['from'] ?? [];
        $initiatorId = (int) ($initiator['id'] ?? 0);
        $targetId    = (int) ($target['id'] ?? 0);

        // --- Basic cut-offs ---
        if ($initiatorId === 0 || $targetId === 0) {
            return;
        }
        if ($targetId === $initiatorId || !empty($target['is_bot'])) {
            // Can't vote against yourself or against a bot.
            self::deny($chatId, $group, $threadId);
            return;
        }

        // A vote against this target is already active — don't open a second one.
        $tVotes = DB::table('votes');
        $active = DB::fetchColumn(
            "SELECT 1 FROM {$tVotes} WHERE chat_id = ? AND target_id = ? AND status = 'active' LIMIT 1",
            [$chatId, $targetId]
        );
        if ($active) {
            Logger::debug('VoteManager: a vote against this target is already running', ['chat_id' => $chatId, 'target' => $targetId]);
            return;
        }

        // Counter-attack protection: you can't vote against the initiator of an active vote,
        // nor for cooldown_hours after it finished.
        $cooldown = (int) ($group['cooldown_hours'] ?? 0);
        $counterAttack = DB::fetchColumn(
            "SELECT 1 FROM {$tVotes}
              WHERE chat_id = ? AND initiator_id = ?
                AND (status = 'active' OR (finished_at IS NOT NULL AND finished_at > NOW() - INTERVAL ? HOUR))
              LIMIT 1",
            [$chatId, $targetId, $cooldown]
        );
        if ($counterAttack) {
            Logger::info('VoteManager: counter-attack attempt rejected', ['chat_id' => $chatId, 'target' => $targetId]);
            self::deny($chatId, $group, $threadId);
            return;
        }

        // Can't put a group admin/owner up for a vote.
        if (GroupManager::isAdmin($chatId, $targetId)) {
            Logger::info('VoteManager: target is an admin, voting forbidden', ['chat_id' => $chatId, 'target' => $targetId]);
            self::deny($chatId, $group, $threadId);
            return;
        }

        // --- Initiator eligibility ---
        $initP = GroupManager::ensureParticipant($chatId, $initiatorId, $initiator['username'] ?? null);
        if (!self::canParticipate($chatId, $initiatorId, $group)) {
            Logger::debug('VoteManager: initiator not eligible', ['chat_id' => $chatId, 'user' => $initiatorId]);
            self::deny($chatId, $group, $threadId);
            return;
        }

        // --- Target protection ---
        $targetP = GroupManager::ensureParticipant($chatId, $targetId, $target['username'] ?? null);
        if ((int) ($targetP['is_protected'] ?? 0) === 1) {
            Logger::info('VoteManager: target is protected', ['chat_id' => $chatId, 'target' => $targetId]);
            self::deny($chatId, $group, $threadId);
            return;
        }

        // --- Threshold: if the target is an elder, protected_ban_threshold applies ---
        $targetElder = self::isElder($group, $targetP);
        $initElder   = self::isElder($group, $initP);
        $threshold = $targetElder
            ? (float) $group['protected_ban_threshold']
            : (float) $group['ban_threshold'];

        // --- Create the vote ---
        $triggerText = $reply['text'] ?? ($reply['caption'] ?? null);
        if (is_string($triggerText) && mb_strlen($triggerText) > 1000) {
            $triggerText = mb_substr($triggerText, 0, 1000);
        }
        $triggerDate = isset($reply['date']) ? (int) $reply['date'] : null;

        DB::run(
            "INSERT INTO {$tVotes}
                (chat_id, target_id, initiator_id, trigger_msg_id, trigger_text, trigger_date, started_at, status, used_threshold, thread_id)
             VALUES (?, ?, ?, ?, ?, " . ($triggerDate !== null ? 'FROM_UNIXTIME(?)' : 'NULL') . ", NOW(), 'active', ?, ?)",
            $triggerDate !== null
                ? [$chatId, $targetId, $initiatorId, (int) ($reply['message_id'] ?? 0), $triggerText, $triggerDate, $threshold, $threadId]
                : [$chatId, $targetId, $initiatorId, (int) ($reply['message_id'] ?? 0), $triggerText, $threshold, $threadId]
        );
        $voteId = (int) DB::lastInsertId();

        // Telemetry: a vote was opened (the top of the moderation funnel).
        Telemetry::record('vote_created', $chatId);

        // Suspect marker (created for every vote).
        DB::run(
            "INSERT INTO " . DB::table('suspects') . " (chat_id, user_id, vote_id, is_elder_conflict)
             VALUES (?, ?, ?, ?)",
            [$chatId, $targetId, $voteId, ($targetElder && $initElder) ? 1 : 0]
        );

        $isAdminInit  = GroupManager::isAdmin($chatId, $initiatorId);
        $adminInstant = (int) ($group['admin_instant_ban'] ?? 0) === 1;

        // The initiator's implicit "for" vote:
        //  - a regular initiator → counted (as before, "1 of N");
        //  - admin + instant_ban=on → counted (will appear in the list on ban);
        //  - admin + instant_ban=off → NOT counted: let the admin re-check the target and
        //    decide with a button (or leave it to the community).
        $forSum = 0.0;
        if (!($isAdminInit && !$adminInstant)) {
            $initWeight = self::weightOf($group, $initP);
            DB::run(
                "INSERT INTO " . DB::table('vote_records') . " (vote_id, voter_id, direction, weight, voted_at)
                 VALUES (?, ?, 'for', ?, NOW())",
                [$voteId, $initiatorId, $initWeight]
            );
            $forSum = $initWeight;
        }

        // --- Delete the activation message (reply with @mention) ---
        if ((int) ($group['delete_trigger_message'] ?? 0) === 1) {
            Bot::call('deleteMessage', [
                'chat_id'    => $chatId,
                'message_id' => (int) ($message['message_id'] ?? 0),
            ]);
        }

        Logger::info('VoteManager: vote created', [
            'vote_id' => $voteId, 'chat_id' => $chatId, 'target' => $targetId, 'threshold' => $threshold,
        ]);

        // Admin initiator with admin_instant_ban=on → instant ban without buttons
        // (finalize posts the result, to avoid flicker).
        if ($isAdminInit && $adminInstant) {
            Logger::info('VoteManager: admin initiator, instant_ban=on → instant ban', ['vote_id' => $voteId, 'target' => $targetId]);
            self::finalize($voteId, 'banned', 'admin');
            return;
        }

        // The initiator's implicit vote already reaches the ban threshold (e.g. threshold = 1,
        // or an elder initiator whose weight ≥ threshold) → ban immediately, without an
        // interactive message (as with admin_instant; finalize posts the result). Without
        // this the vote would "hang" with the count reached until the first button press.
        if ($forSum > 0 && $forSum >= $threshold) {
            Logger::info('VoteManager: threshold reached by the initiator\'s vote → instant ban', [
                'vote_id' => $voteId, 'for' => $forSum, 'threshold' => $threshold,
            ]);
            self::finalize($voteId, 'banned');
            return;
        }

        // Otherwise — the vote message with buttons. For an admin in off mode the "for"
        // counter is 0 (they decide by pressing, or leave it to the community).
        $text = Lang::get('vote_open', $lang, [
            'initiator' => self::displayName($initiator, $lang),
            'target'    => self::displayName($target, $lang),
        ]);
        $sent = Bot::call('sendMessage', self::withThread([
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => self::keyboard($group, $voteId, $forSum, 0.0, $threshold),
        ], $threadId));
        if (is_array($sent) && isset($sent['message_id'])) {
            DB::run(
                "UPDATE {$tVotes} SET vote_message_id = ? WHERE id = ?",
                [(int) $sent['message_id'], $voteId]
            );
        } else {
            Logger::error('VoteManager: failed to send the vote message', null, ['vote_id' => $voteId]);
        }

        // Notify opted-in admins that a vote went live.
        Notifier::voteStarted($chatId, $initiatorId, $targetId, $voteId);
    }

    /**
     * Record a vote from a button press (callback_query): store the vote, update counters,
     * then evaluate thresholds/finalization.
     *
     * @param array<string, mixed> $cb the callback_query object
     */
    public static function castVote(array $cb): void
    {
        $cbId = $cb['id'] ?? null;
        $data = (string) ($cb['data'] ?? '');

        if (!preg_match('/^v:([fa]):(\d+)$/', $data, $m)) {
            self::answer($cbId);
            return;
        }
        $direction = $m[1] === 'f' ? 'for' : 'against';
        $voteId    = (int) $m[2];

        $tVotes = DB::table('votes');
        $vote = DB::fetch("SELECT * FROM {$tVotes} WHERE id = ?", [$voteId]);
        if ($vote === null) {
            self::answer($cbId);
            return;
        }

        $chatId = (int) $vote['chat_id'];
        $group  = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::answer($cbId);
            return;
        }
        $lang = (string) ($group['lang'] ?? 'ru');

        if ($vote['status'] !== 'active') {
            self::answer($cbId, Lang::get('vote_finished', $lang));
            return;
        }

        $voter   = $cb['from'] ?? [];
        $voterId = (int) ($voter['id'] ?? 0);

        // The target doesn't vote for/against their own ban.
        if ($voterId === 0 || $voterId === (int) $vote['target_id']) {
            self::answer($cbId, Lang::get('action_denied', $lang));
            return;
        }

        // "Admin is king": an admin can always vote; their press finalizes the vote instantly
        // (below). Eligibility (tenure / min_messages / join time) is NOT checked for admins.
        $isAdminVoter = GroupManager::isAdmin($chatId, $voterId);

        $p = GroupManager::ensureParticipant($chatId, $voterId, $voter['username'] ?? null);
        if (!$isAdminVoter && !self::canParticipate($chatId, $voterId, $group, (string) $vote['started_at'])) {
            self::answer($cbId, Lang::get('action_denied', $lang));
            return;
        }

        $weight = self::weightOf($group, $p);

        // Record the vote in a transaction with a row lock on the vote.
        DB::begin();
        try {
            $locked = DB::fetch("SELECT status FROM {$tVotes} WHERE id = ? FOR UPDATE", [$voteId]);
            if ($locked === null || $locked['status'] !== 'active') {
                DB::rollBack();
                self::answer($cbId, Lang::get('vote_finished', $lang));
                return;
            }

            $existing = DB::fetch(
                "SELECT id, direction FROM " . DB::table('vote_records') . " WHERE vote_id = ? AND voter_id = ?",
                [$voteId, $voterId]
            );
            if ($existing !== null) {
                if ($existing['direction'] === $direction) {
                    // Same choice — change nothing.
                    DB::rollBack();
                    self::answer($cbId, Lang::get('already_voted', $lang));
                    return;
                }
                // Re-vote: move the vote to the other side.
                DB::run(
                    "UPDATE " . DB::table('vote_records') . "
                        SET direction = ?, weight = ?, voted_at = NOW() WHERE id = ?",
                    [$direction, $weight, (int) $existing['id']]
                );
            } else {
                DB::run(
                    "INSERT INTO " . DB::table('vote_records') . " (vote_id, voter_id, direction, weight, voted_at)
                     VALUES (?, ?, ?, ?, NOW())",
                    [$voteId, $voterId, $direction, $weight]
                );
            }
            $sums = self::sums($voteId);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error('VoteManager: error recording a vote', $e, ['vote_id' => $voteId]);
            self::answer($cbId);
            return;
        }

        // --- Evaluate the outcome ---
        $usedThreshold    = (float) $vote['used_threshold'];
        $declineThreshold = (float) $group['ban_decline_threshold'];

        // An admin's vote finalizes the vote instantly.
        if ($isAdminVoter) {
            self::answer($cbId);
            self::finalize($voteId, $direction === 'for' ? 'banned' : 'declined', 'admin');
            return;
        }
        // Ban threshold reached?
        if ($sums['for'] >= $usedThreshold) {
            self::answer($cbId);
            self::finalize($voteId, 'banned');
            return;
        }
        // Decline threshold reached?
        if ($sums['against'] >= $declineThreshold) {
            self::answer($cbId);
            self::finalize($voteId, 'declined');
            return;
        }

        // Interim readonly for the target once a fraction of the "for" threshold is reached.
        $readonlyThreshold = $usedThreshold * (float) $group['readonly_ratio'];
        if (($vote['readonly_at'] ?? null) === null && $readonlyThreshold > 0 && $sums['for'] >= $readonlyThreshold) {
            self::applyReadonly($chatId, (int) $vote['target_id']);
            DB::run("UPDATE {$tVotes} SET readonly_at = NOW() WHERE id = ?", [$voteId]);
            Logger::info('VoteManager: target put into readonly', ['vote_id' => $voteId, 'target' => $vote['target_id']]);
        }

        // Otherwise — just refresh the counters on the buttons.
        self::refreshKeyboard($chatId, (int) $vote['vote_message_id'], $group, $voteId, $usedThreshold, $sums);
        self::answer($cbId);
        Logger::debug('VoteManager: vote counted', [
            'vote_id' => $voteId, 'voter' => $voterId, 'dir' => $direction,
            'for' => $sums['for'], 'against' => $sums['against'],
        ]);
    }

    // =====================================================================
    // Helpers (some reused during vote tallying)
    // =====================================================================

    /**
     * Whether a participant may vote/initiate: tenure (min_age_hours), min_messages in full
     * mode, and (if $beforeTs is given) having joined before that timestamp — for voters
     * that's the vote's started_at.
     */
    private static function canParticipate(int $chatId, int $userId, array $group, ?string $beforeTs = null): bool
    {
        $sql = "SELECT (joined_at <= NOW() - INTERVAL ? HOUR) AS tenure_ok, "
             . ($beforeTs !== null ? "(joined_at <= ?) AS before_ok, " : "")
             . "msg_count FROM " . DB::table('participants') . " WHERE chat_id = ? AND user_id = ?";

        $params = [(int) $group['min_age_hours']];
        if ($beforeTs !== null) {
            $params[] = $beforeTs;
        }
        $params[] = $chatId;
        $params[] = $userId;

        $row = DB::fetch($sql, $params);
        if ($row === null || (int) $row['tenure_ok'] !== 1) {
            return false;
        }
        if ($beforeTs !== null && (int) $row['before_ok'] !== 1) {
            return false;
        }
        if (($group['mode'] ?? 'light') === 'full' && (int) $row['msg_count'] < (int) $group['min_messages']) {
            return false;
        }
        return true;
    }

    /** Whether a participant is an elder (full mode only). */
    private static function isElder(array $group, array $participant): bool
    {
        return ($group['mode'] ?? 'light') === 'full'
            && (float) ($participant['score'] ?? 0) >= (float) $group['elder_threshold'];
    }

    /** A participant's vote weight: elder_weight for an elder, otherwise 1.0. */
    private static function weightOf(array $group, array $participant): float
    {
        return self::isElder($group, $participant) ? (float) $group['elder_weight'] : 1.0;
    }

    /** "You can't do this right now" with auto-deletion. */
    private static function deny(int $chatId, array $group, ?int $threadId = null): void
    {
        self::ephemeral($chatId, $group, Lang::get('action_denied', (string) ($group['lang'] ?? 'ru')), $threadId);
    }

    /**
     * Explain how to start a vote (self-deleting). Used when the bot is mentioned in a group but
     * the message isn't a valid trigger — no reply, or extra text besides the bare mention — so
     * a stray "@bot" or an explanatory sentence never silently votes someone out. $threadId keeps
     * the hint in the same forum topic the bot was mentioned in.
     */
    public static function hintHowToVote(int $chatId, ?int $threadId = null): void
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $botUser = (string) Config::value('bot', 'BOT_USERNAME', '');
        self::ephemeral($chatId, $group, Lang::get('vote_howto', (string) ($group['lang'] ?? 'ru'), ['bot' => $botUser]), $threadId);
    }

    /** Temporary message auto-deleted after cleanup_delay. Posted into $threadId if given. */
    private static function ephemeral(int $chatId, array $group, string $text, ?int $threadId = null): void
    {
        $res = Bot::call('sendMessage', self::withThread(['chat_id' => $chatId, 'text' => $text], $threadId));
        if (is_array($res) && isset($res['message_id'])) {
            self::scheduleDelete($chatId, (int) $res['message_id'], (int) ($group['cleanup_delay_seconds'] ?? 60));
        }
    }

    /**
     * The forum topic (message thread) a message belongs to, or null. Set ONLY for real forum-topic
     * messages: passing message_thread_id in a non-forum chat (or for "General") errors, so we key
     * off is_topic_message.
     *
     * @param array<string, mixed> $message
     */
    public static function threadOf(array $message): ?int
    {
        if (!empty($message['is_topic_message']) && isset($message['message_thread_id'])) {
            return (int) $message['message_thread_id'];
        }
        return null;
    }

    /**
     * Add message_thread_id to sendMessage params when we have a topic, so the message lands in the
     * same forum topic as the trigger. No-op for ordinary groups / General.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function withThread(array $params, ?int $threadId): array
    {
        if ($threadId !== null && $threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        return $params;
    }

    /** Record a message in bot_messages for deferred deletion (cron). */
    private static function scheduleDelete(int $chatId, int $messageId, int $delaySeconds): void
    {
        DB::run(
            "INSERT INTO " . DB::table('bot_messages') . " (chat_id, message_id, delete_at)
             VALUES (?, ?, NOW() + INTERVAL ? SECOND)",
            [$chatId, $messageId, $delaySeconds]
        );
    }

    /** Display name: @username → name → "(unknown)". No links. */
    private static function displayName(array $user, string $lang): string
    {
        $username = (string) ($user['username'] ?? '');
        if ($username !== '') {
            return '@' . $username;
        }
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        return $name !== '' ? $name : Lang::get('unknown_user', $lang);
    }

    /** Number without trailing zeros: 3.0 → "3", 4.5 → "4.5". */
    private static function fmtNum(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * Vote inline keyboard: "✅ For — X/N" and "❌ Against — Y/M".
     *
     * @return array{inline_keyboard: array<int, array<int, array{text:string, callback_data:string}>>}
     */
    private static function keyboard(array $group, int $voteId, float $forSum, float $againstSum, float $banThreshold): array
    {
        $lang     = (string) ($group['lang'] ?? 'ru');
        $declineT = (float) $group['ban_decline_threshold'];

        return ['inline_keyboard' => [[
            [
                'text'          => Lang::get('btn_for', $lang, ['cur' => self::fmtNum($forSum), 'max' => self::fmtNum($banThreshold)]),
                'callback_data' => "v:f:{$voteId}",
            ],
            [
                'text'          => Lang::get('btn_against', $lang, ['cur' => self::fmtNum($againstSum), 'max' => self::fmtNum($declineT)]),
                'callback_data' => "v:a:{$voteId}",
            ],
        ]]];
    }

    /** Answer a callback_query (clear the spinner), with an optional hint text. */
    private static function answer(?string $cbId, ?string $text = null): void
    {
        if ($cbId === null) {
            return;
        }
        $params = ['callback_query_id' => $cbId];
        if ($text !== null) {
            $params['text'] = $text;
        }
        Bot::call('answerCallbackQuery', $params);
    }

    /**
     * Current weight sums by vote direction.
     *
     * @return array{for: float, against: float}
     */
    private static function sums(int $voteId): array
    {
        $rows = DB::fetchAll(
            "SELECT direction, SUM(weight) AS s FROM " . DB::table('vote_records') . "
              WHERE vote_id = ? GROUP BY direction",
            [$voteId]
        );
        $out = ['for' => 0.0, 'against' => 0.0];
        foreach ($rows as $r) {
            $out[$r['direction']] = (float) $r['s'];
        }
        return $out;
    }

    /**
     * Update the vote message buttons with the new counters.
     * If the message is gone (deleted) — treat it as a manual cancellation.
     *
     * @param array{for: float, against: float} $sums
     */
    private static function refreshKeyboard(int $chatId, int $messageId, array $group, int $voteId, float $threshold, array $sums): void
    {
        if ($messageId <= 0) {
            return;
        }
        $res = Bot::call('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => self::keyboard($group, $voteId, $sums['for'], $sums['against'], $threshold),
        ]);
        if ($res === null && Bot::messageGone()) {
            Logger::warning('VoteManager: vote message was deleted → cancel', null, ['vote_id' => $voteId]);
            self::finalize($voteId, 'cancelled', 'cancelled');
        }
    }

    // =====================================================================
    // Vote finalization
    // =====================================================================

    /**
     * Admin finalization by vote id (from a reply-to-notification command in a DM). Returns
     * true if the vote was still active and got finalized, false if it had already finished.
     * $cause defaults to 'admin' (this path is always an admin action); the data-erasure sweep
     * passes 'erase' so the telemetry bucket is right.
     */
    public static function finalizeByAdmin(int $voteId, string $outcome, string $cause = 'admin'): bool
    {
        $status = DB::fetchColumn("SELECT status FROM " . DB::table('votes') . " WHERE id = ?", [$voteId]);
        if ($status !== 'active') {
            return false;
        }
        self::finalize($voteId, $outcome, $cause);
        return true;
    }

    /**
     * Finalize a vote: banned | declined | expired | cancelled.
     * Idempotent — the status is switched from 'active' under a row lock, so repeated/racing
     * calls are safe.
     *
     * $cause records HOW it ended for telemetry (the same outcome can arrive via different paths —
     * a group button, a DM command, the cron, a manual Telegram ban): 'community' (threshold reached
     * by members), 'admin' (an admin forced it), 'manual' (banned directly in Telegram), 'timeout'
     * (cron), 'cancelled' (vote message deleted), 'erase' (data erasure). One record() call here
     * covers every path.
     */
    private static function finalize(int $voteId, string $outcome, string $cause = 'community'): void
    {
        $tVotes = DB::table('votes');

        DB::begin();
        $vote = DB::fetch("SELECT * FROM {$tVotes} WHERE id = ? FOR UPDATE", [$voteId]);
        if ($vote === null || $vote['status'] !== 'active') {
            DB::rollBack();
            return;
        }
        DB::run("UPDATE {$tVotes} SET status = ?, finished_at = NOW() WHERE id = ?", [$outcome, $voteId]);
        DB::commit();

        $chatId = (int) $vote['chat_id'];

        // Telemetry: counted exactly once (we only get past the commit when flipping active → done).
        $event = self::telemetryEvent($outcome, $cause);
        if ($event !== null) {
            Telemetry::record($event, $chatId);
        }

        $group  = GroupManager::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $lang        = (string) ($group['lang'] ?? 'ru');
        $targetId    = (int) $vote['target_id'];
        $msgId       = (int) ($vote['vote_message_id'] ?? 0);
        $threadId    = isset($vote['thread_id']) ? (int) $vote['thread_id'] : null;
        $wasReadonly = ($vote['readonly_at'] ?? null) !== null;

        Logger::info('VoteManager: vote finished', ['vote_id' => $voteId, 'outcome' => $outcome]);

        // Notify opted-in admins of the outcome (banned / declined / expired / cancelled).
        Notifier::voteFinished($chatId, $targetId, $outcome);

        if ($outcome === 'banned') {
            Bot::call('banChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
            GroupManager::onMemberBanned($chatId, $targetId);

            if ((int) ($group['delete_spam_on_ban'] ?? 0) === 1 && !empty($vote['trigger_msg_id'])) {
                Bot::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int) $vote['trigger_msg_id']]);
            }

            $base        = Lang::get('ban_success', $lang, ['target' => self::nameById($chatId, $targetId, $lang)]);
            $showList    = (int) ($group['show_full_list'] ?? 0) === 1;
            $revealDelay = (int) ($group['reveal_delay_seconds'] ?? 0);

            if ($showList && $revealDelay > 0) {
                // Show the ban line; cron adds the list after reveal_delay.
                // vote_message_id (new or existing) is the marker of a pending reveal.
                if ($msgId > 0) {
                    Bot::call('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $base]);
                } else {
                    $sent = Bot::call('sendMessage', self::withThread(['chat_id' => $chatId, 'text' => $base], $threadId));
                    if (is_array($sent) && isset($sent['message_id'])) {
                        DB::run("UPDATE {$tVotes} SET vote_message_id = ? WHERE id = ?", [(int) $sent['message_id'], $voteId]);
                    }
                }
            } else {
                // Full result at once (with the list if show_full_list).
                $full = self::banText($chatId, $voteId, $group, $base);
                if ($msgId > 0) {
                    Bot::call('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $full]);
                    DB::run("UPDATE {$tVotes} SET vote_message_id = NULL WHERE id = ?", [$voteId]);
                } else {
                    Bot::call('sendMessage', self::withThread(['chat_id' => $chatId, 'text' => $full], $threadId));
                }
            }
            return;
        }

        // declined | expired | cancelled — lift readonly, show the result and remove it.
        if ($wasReadonly) {
            self::liftReadonly($chatId, $targetId);
        }
        if ($msgId > 0) {
            $text = Lang::get($outcome, $lang, ['target' => self::nameById($chatId, $targetId, $lang)]);
            $res  = Bot::call('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text]);
            if ($res !== null) {
                self::scheduleDelete($chatId, $msgId, (int) ($group['cleanup_delay_seconds'] ?? 60));
            }
            DB::run("UPDATE {$tVotes} SET vote_message_id = NULL WHERE id = ?", [$voteId]);
        }
    }

    /**
     * Map a finalization (outcome + cause) to a telemetry event code, or null if not counted.
     * "community reached for/against", "admin forced it", "manual TG ban", "timeout", "cancelled"
     * are separate buckets so the operator sees the funnel the way it actually happens.
     */
    private static function telemetryEvent(string $outcome, string $cause): ?string
    {
        switch ($outcome) {
            case 'banned':
                return $cause === 'admin' ? 'vote_banned_admin'
                    : ($cause === 'manual' ? 'vote_banned_manual' : 'vote_banned_vote');
            case 'declined':
                return $cause === 'admin' ? 'vote_declined_admin' : 'vote_declined_vote';
            case 'expired':
                return 'vote_expired';
            case 'cancelled':
                return $cause === 'admin' ? 'vote_cancelled_admin' : 'vote_cancelled';
            default:
                return null;
        }
    }

    /** Final ban text: the ban line + (if show_full_list) the voter lists. */
    private static function banText(int $chatId, int $voteId, array $group, string $base): string
    {
        if ((int) ($group['show_full_list'] ?? 0) !== 1) {
            return $base;
        }
        $lang = (string) ($group['lang'] ?? 'ru');
        return $base
            . "\n" . Lang::get('ban_voters_for', $lang, ['list' => self::voterList($chatId, $voteId, 'for', $lang)])
            . "\n" . Lang::get('ban_voters_against', $lang, ['list' => self::voterList($chatId, $voteId, 'against', $lang)]);
    }

    // =====================================================================
    // Vote cron tasks
    // =====================================================================

    /**
     * Delete FINISHED votes older than $days days together with their vote_records and
     * suspect markers (cron: data_ttl). Active votes are untouched. Returns the number of
     * votes deleted.
     */
    public static function purgeOldVotes(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $v  = DB::table('votes');
        $vr = DB::table('vote_records');
        $s  = DB::table('suspects');

        DB::run(
            "DELETE vr FROM {$vr} vr JOIN {$v} v ON vr.vote_id = v.id
              WHERE v.finished_at IS NOT NULL AND v.finished_at < NOW() - INTERVAL ? DAY",
            [$days]
        );
        DB::run(
            "DELETE s FROM {$s} s JOIN {$v} v ON s.vote_id = v.id
              WHERE v.finished_at IS NOT NULL AND v.finished_at < NOW() - INTERVAL ? DAY",
            [$days]
        );
        return DB::run(
            "DELETE FROM {$v} WHERE finished_at IS NOT NULL AND finished_at < NOW() - INTERVAL ? DAY",
            [$days]
        )->rowCount();
    }

    /** Cron: close timed-out votes (T1/T2) and reveal pending lists. */
    public static function runTimeouts(): void
    {
        self::closeExpired();
        self::revealPending();
    }

    /** Close active votes past their timeout (T1 without "against" / T2 with "against"). */
    private static function closeExpired(): void
    {
        $v = DB::table('votes');
        $g = DB::table('groups');
        $r = DB::table('vote_records');

        $rows = DB::fetchAll(
            "SELECT v.id,
                    EXISTS(SELECT 1 FROM {$r} r WHERE r.vote_id = v.id AND r.direction = 'against') AS has_against,
                    (v.started_at <= NOW() - INTERVAL g.T1_hours HOUR) AS past_t1,
                    (v.started_at <= NOW() - INTERVAL g.T2_hours HOUR) AS past_t2
               FROM {$v} v JOIN {$g} g ON g.chat_id = v.chat_id
              WHERE v.status = 'active'"
        );
        foreach ($rows as $row) {
            $expired = ((int) $row['has_against'] === 0 && (int) $row['past_t1'] === 1)
                    || ((int) $row['has_against'] === 1 && (int) $row['past_t2'] === 1);
            if ($expired) {
                self::finalize((int) $row['id'], 'expired', 'timeout');
            }
        }
    }

    /** Reveal voter lists for banned votes once reveal_delay has passed. */
    private static function revealPending(): void
    {
        $v = DB::table('votes');
        $g = DB::table('groups');

        $rows = DB::fetchAll(
            "SELECT v.id, v.chat_id, v.vote_message_id, v.target_id
               FROM {$v} v JOIN {$g} g ON g.chat_id = v.chat_id
              WHERE v.status = 'banned' AND v.vote_message_id IS NOT NULL
                AND g.show_full_list = 1
                AND v.finished_at <= NOW() - INTERVAL g.reveal_delay_seconds SECOND"
        );
        foreach ($rows as $row) {
            $chatId = (int) $row['chat_id'];
            $group  = GroupManager::getGroup($chatId) ?? [];
            $lang   = (string) ($group['lang'] ?? 'ru');
            $base   = Lang::get('ban_success', $lang, ['target' => self::nameById($chatId, (int) $row['target_id'], $lang)]);
            Bot::call('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => (int) $row['vote_message_id'],
                'text'       => self::banText($chatId, (int) $row['id'], $group, $base),
            ]);
            DB::run("UPDATE {$v} SET vote_message_id = NULL WHERE id = ?", [(int) $row['id']]);
        }
    }

    /** Cron: delete deferred service messages (bot_messages) via deleteMessage. */
    public static function cleanupBotMessages(): void
    {
        $t = DB::table('bot_messages');
        $rows = DB::fetchAll("SELECT id, chat_id, message_id FROM {$t} WHERE delete_at <= NOW()");
        foreach ($rows as $row) {
            Bot::call('deleteMessage', ['chat_id' => (int) $row['chat_id'], 'message_id' => (int) $row['message_id']]);
            DB::run("DELETE FROM {$t} WHERE id = ?", [(int) $row['id']]);
        }
    }

    /**
     * Cancel every active vote in a group that involves the given user (as target or initiator).
     * Used before erasing a user's data so no live vote is left pointing at a removed participant.
     * Returns the number of votes cancelled.
     */
    public static function cancelActiveForUser(int $chatId, int $userId): int
    {
        $rows = DB::fetchAll(
            "SELECT id FROM " . DB::table('votes') . "
              WHERE chat_id = ? AND status = 'active' AND (target_id = ? OR initiator_id = ?)",
            [$chatId, $userId, $userId]
        );
        $n = 0;
        foreach ($rows as $row) {
            if (self::finalizeByAdmin((int) $row['id'], 'cancelled', 'erase')) {
                $n++;
            }
        }
        return $n;
    }

    /** Manual ban (an admin banned directly in Telegram): close the active vote as a ban. */
    public static function onManualBan(int $chatId, int $userId): void
    {
        $voteId = DB::fetchColumn(
            "SELECT id FROM " . DB::table('votes') . " WHERE chat_id = ? AND target_id = ? AND status = 'active' LIMIT 1",
            [$chatId, $userId]
        );
        if ($voteId !== null) {
            self::finalize((int) $voteId, 'banned', 'manual');
        }
    }

    // =====================================================================
    // readonly and name resolution
    // =====================================================================

    private static function applyReadonly(int $chatId, int $userId): void
    {
        Bot::call('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'permissions' => self::perms(false)]);
    }

    private static function liftReadonly(int $chatId, int $userId): void
    {
        Bot::call('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'permissions' => self::perms(true)]);
    }

    /** A ChatPermissions set: deny all ($allow=false) or allow all ($allow=true). */
    private static function perms(bool $allow): array
    {
        return [
            'can_send_messages'        => $allow,
            'can_send_audios'          => $allow,
            'can_send_documents'       => $allow,
            'can_send_photos'          => $allow,
            'can_send_videos'          => $allow,
            'can_send_video_notes'     => $allow,
            'can_send_voice_notes'     => $allow,
            'can_send_polls'           => $allow,
            'can_send_other_messages'  => $allow,
            'can_add_web_page_previews' => $allow,
        ];
    }

    /** Participant name by id from the DB: @username or "(unknown)". */
    private static function nameById(int $chatId, int $userId, string $lang): string
    {
        $u = DB::fetchColumn(
            "SELECT username FROM " . DB::table('participants') . " WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return ($u !== null && $u !== '') ? '@' . $u : Lang::get('unknown_user', $lang);
    }

    /** List of voters in a direction (for the final message). */
    private static function voterList(int $chatId, int $voteId, string $direction, string $lang): string
    {
        $rows = DB::fetchAll(
            "SELECT p.username
               FROM " . DB::table('vote_records') . " r
               LEFT JOIN " . DB::table('participants') . " p ON p.chat_id = ? AND p.user_id = r.voter_id
              WHERE r.vote_id = ? AND r.direction = ?",
            [$chatId, $voteId, $direction]
        );
        $names = [];
        foreach ($rows as $row) {
            $u = (string) ($row['username'] ?? '');
            $names[] = $u !== '' ? '@' . $u : Lang::get('unknown_user', $lang);
        }
        return $names !== [] ? implode(', ', $names) : '—';
    }
}
