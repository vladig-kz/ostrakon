<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * ScoreManager — activity accounting for members (FULL mode ONLY).
 *
 * Message text is NOT stored: only metadata is written to the messages table (who, when,
 * what it replied to) — enough for computing the score and for TTL cleanup. The score
 * itself is recomputed periodically (cron: score_recalc), not on every message.
 */
final class ScoreManager
{
    /**
     * Record a member's message (if it passes the quality filter): write metadata to
     * messages and increment participants.msg_count. Called from the Handler only for
     * groups in full mode.
     *
     * @param array<string, mixed> $message the message object
     * @param array<string, mixed> $group   a groups table row
     */
    public static function recordMessage(array $message, array $group): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from   = $message['from'] ?? [];
        $userId = (int) ($from['id'] ?? 0);

        // Ignore bots and messages without a sender/chat.
        if ($chatId === 0 || $userId === 0 || !empty($from['is_bot'])) {
            return;
        }

        // Length filter: short messages (and text-less ones — stickers/media without a caption) don't count.
        $text   = (string) ($message['text'] ?? $message['caption'] ?? '');
        $minLen = (int) ($group['min_msg_length'] ?? 0);
        if (mb_strlen(trim($text)) < $minLen) {
            return;
        }

        // Anti-flood: at most one counted message per msg_cooldown_minutes.
        $cooldown = (int) ($group['msg_cooldown_minutes'] ?? 0);
        if ($cooldown > 0) {
            $recent = DB::fetchColumn(
                "SELECT 1 FROM " . DB::table('messages') . "
                  WHERE chat_id = ? AND user_id = ? AND sent_at > NOW() - INTERVAL ? MINUTE LIMIT 1",
                [$chatId, $userId, $cooldown]
            );
            if ($recent) {
                return;
            }
        }

        GroupManager::ensureParticipant($chatId, $userId, $from['username'] ?? null);

        $date    = isset($message['date']) ? (int) $message['date'] : null;
        $replyTo = (int) ($message['reply_to_message']['message_id'] ?? 0) ?: null;

        DB::run(
            "INSERT INTO " . DB::table('messages') . " (chat_id, user_id, sent_at, reply_to_msg_id)
             VALUES (?, ?, " . ($date !== null ? 'FROM_UNIXTIME(?)' : 'NOW()') . ", ?)",
            $date !== null ? [$chatId, $userId, $date, $replyTo] : [$chatId, $userId, $replyTo]
        );
        DB::run(
            "UPDATE " . DB::table('participants') . " SET msg_count = msg_count + 1 WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );

        Logger::trace('ScoreManager: message counted', ['chat_id' => $chatId, 'user' => $userId]);
    }

    /**
     * Recompute the score for all full-mode groups (cron: score_recalc).
     * score = Σ exp(−λ·age_days), λ = ln2 / halflife_days — each message's contribution
     * halves every halflife_days. A member with score ≥ elder_threshold is an "elder".
     */
    public static function recalcAll(): void
    {
        $groups = DB::fetchAll(
            "SELECT chat_id, halflife_days, elder_threshold, elder_title
               FROM " . DB::table('groups') . " WHERE mode = 'full'"
        );
        foreach ($groups as $g) {
            $chatId    = (int) $g['chat_id'];
            $threshold = (float) $g['elder_threshold'];
            self::recalcGroup($chatId, (float) $g['halflife_days']);
            self::applyElderStatus($chatId, $threshold);                       // status + notifications (always)
            self::applyElderTags($chatId, $threshold, (string) $g['elder_title']); // visible tag (only if elder_title set)
        }
        Logger::info('ScoreManager: score_recalc finished', ['groups' => count($groups)]);
    }

    /**
     * Apply/remove the visible elder tag (Bot API 9.5 setChatMemberTag) based on the
     * freshly recomputed score. Calls Telegram only on transitions, tracked via
     * participants.elder_tagged. Empty elder_title disables tagging (the on/off switch).
     * Requires the bot's can_manage_tags admin right.
     */
    public static function applyElderTags(int $chatId, float $threshold, string $title): void
    {
        $title = mb_substr(trim($title), 0, 16); // Telegram tag length limit
        if ($title === '') {
            return; // tagging disabled for this group
        }
        $p = DB::table('participants');

        // Newly elders (not yet tagged, not banned) → set the tag.
        $toTag = DB::fetchAll(
            "SELECT user_id FROM {$p} WHERE chat_id = ? AND elder_tagged = 0 AND banned_at IS NULL AND score >= ?",
            [$chatId, $threshold]
        );
        foreach ($toTag as $r) {
            $uid = (int) $r['user_id'];
            $res = Bot::call('setChatMemberTag', ['chat_id' => $chatId, 'user_id' => $uid, 'tag' => $title]);
            if ($res !== null) {
                DB::run("UPDATE {$p} SET elder_tagged = 1 WHERE chat_id = ? AND user_id = ?", [$chatId, $uid]);
            }
        }

        // No longer elders (or banned) but still tagged → clear the tag.
        $toClear = DB::fetchAll(
            "SELECT user_id FROM {$p} WHERE chat_id = ? AND elder_tagged = 1 AND (score < ? OR banned_at IS NOT NULL)",
            [$chatId, $threshold]
        );
        foreach ($toClear as $r) {
            $uid = (int) $r['user_id'];
            Bot::call('setChatMemberTag', ['chat_id' => $chatId, 'user_id' => $uid, 'tag' => '']);
            DB::run("UPDATE {$p} SET elder_tagged = 0 WHERE chat_id = ? AND user_id = ?", [$chatId, $uid]);
        }
    }

    /**
     * Track the elder STATUS (score ≥ threshold) via participants.is_elder and notify opted-in
     * admins on transitions. Runs on every recalc regardless of the visible tag, so status
     * notifications work even when tagging (elder_title) is disabled. Pass $notify=false to
     * sync the status silently (e.g. right after an import — no notification burst).
     */
    public static function applyElderStatus(int $chatId, float $threshold, bool $notify = true): void
    {
        $p = DB::table('participants');

        // Became an elder: not marked, not banned, score ≥ threshold.
        $became = DB::fetchAll(
            "SELECT user_id FROM {$p} WHERE chat_id = ? AND is_elder = 0 AND banned_at IS NULL AND score >= ?",
            [$chatId, $threshold]
        );
        foreach ($became as $r) {
            $uid = (int) $r['user_id'];
            DB::run("UPDATE {$p} SET is_elder = 1 WHERE chat_id = ? AND user_id = ?", [$chatId, $uid]);
            if ($notify) {
                Notifier::elderChanged($chatId, $uid, true);
            }
        }

        // No longer an elder (score dropped, or banned) but still marked.
        $lost = DB::fetchAll(
            "SELECT user_id FROM {$p} WHERE chat_id = ? AND is_elder = 1 AND (score < ? OR banned_at IS NOT NULL)",
            [$chatId, $threshold]
        );
        foreach ($lost as $r) {
            $uid = (int) $r['user_id'];
            DB::run("UPDATE {$p} SET is_elder = 0 WHERE chat_id = ? AND user_id = ?", [$chatId, $uid]);
            if ($notify) {
                Notifier::elderChanged($chatId, $uid, false);
            }
        }
    }

    /**
     * Recompute score and elder status/tags for a group right now (used after an import so
     * elders show immediately, not only after the next daily score_recalc). Full mode only;
     * status is synced silently (no notification burst).
     */
    public static function refreshGroupNow(int $chatId): void
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null || ($group['mode'] ?? 'light') !== 'full') {
            return;
        }
        $threshold = (float) ($group['elder_threshold'] ?? 0);
        self::recalcGroup($chatId, (float) ($group['halflife_days'] ?? 1));
        self::applyElderStatus($chatId, $threshold, false);
        self::applyElderTags($chatId, $threshold, (string) ($group['elder_title'] ?? ''));
    }

    /** Recompute the score of all members in a single group. */
    public static function recalcGroup(int $chatId, float $halflifeDays): void
    {
        if ($halflifeDays <= 0) {
            $halflifeDays = 1.0;
        }
        $lambda = log(2) / $halflifeDays; // per day

        $p = DB::table('participants');
        $m = DB::table('messages');
        DB::run(
            "UPDATE {$p} p
                SET p.score = (
                        SELECT COALESCE(SUM(EXP(-? * TIMESTAMPDIFF(SECOND, mm.sent_at, NOW()) / 86400)), 0)
                          FROM {$m} mm
                         WHERE mm.chat_id = p.chat_id AND mm.user_id = p.user_id
                    ),
                    p.score_at = NOW()
              WHERE p.chat_id = ?",
            [$lambda, $chatId]
        );
        Logger::debug('ScoreManager: score recomputed', ['chat_id' => $chatId, 'halflife' => $halflifeDays]);
    }

    /**
     * Manually appoint a member as an elder RIGHT NOW by backfilling a fake message history
     * whose decaying score reaches elder_threshold. The score is then genuine (survives recalc)
     * and decays over time — staying elder afterwards depends on real activity. There is no undo.
     *
     * Full mode only; any admin may do it. Returns: 'ok' | 'already' | 'not_full' | 'no_group'.
     */
    public static function appointElder(int $chatId, int $userId): string
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            return 'no_group';
        }
        if (($group['mode'] ?? 'light') !== 'full') {
            return 'not_full';
        }
        $threshold = (float) ($group['elder_threshold'] ?? 0);
        $halflife  = max(1.0, (float) ($group['halflife_days'] ?? 1));
        if ($threshold <= 0) {
            return 'not_full'; // no reachable elder status configured
        }
        $lambda = log(2) / $halflife;

        if (self::userScore($chatId, $userId, $lambda) >= $threshold) {
            return 'already';
        }

        // Weekly norm to sustain the threshold, rounded UP (per-day is often fractional). Spread
        // it thinly over a window of ~5 half-lives, capped at the retention window (older fakes
        // would be purged by data_ttl). Then top up to the threshold and add one weekly norm at
        // "today" as a ~1-week buffer so the status doesn't drop immediately.
        $weekly      = max(1, (int) ceil($threshold * $lambda * 7));
        $instance    = (array) Config::value('defaults', 'instance', []);
        $historyDays = (int) ($instance['history_days'] ?? 365);
        $windowDays  = max(7, (int) min(5 * $halflife, $historyDays));
        $weeks       = max(1, (int) floor($windowDays / 7));

        $nHist = min(20000, $weekly * $weeks);
        $stepSec = (int) max(1, round($windowDays * 86400 / max(1, $nHist)));

        GroupManager::ensureParticipant($chatId, $userId);

        DB::begin();
        try {
            $ages = [];
            for ($i = 1; $i <= $nHist; $i++) {
                $ages[] = $i * $stepSec;
            }
            self::insertFakeMessages($chatId, $userId, $ages);

            // Measure and top up to the threshold, plus one weekly-norm buffer near "now".
            $shortfall = (int) max(0, (int) ceil($threshold - self::userScore($chatId, $userId, $lambda)));
            $recent    = min(20000, $shortfall + $weekly);
            $recentAges = [];
            for ($j = 0; $j < $recent; $j++) {
                $recentAges[] = (int) round($j * 86400 / max(1, $recent)); // spread over the last day
            }
            self::insertFakeMessages($chatId, $userId, $recentAges);

            DB::run(
                "UPDATE " . DB::table('participants') . " SET msg_count = msg_count + ? WHERE chat_id = ? AND user_id = ?",
                [$nHist + $recent, $chatId, $userId]
            );
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error('ScoreManager: appointElder failed', $e, ['chat_id' => $chatId, 'user' => $userId]);
            throw $e;
        }

        // Reflect immediately, reusing the recalc trio (recompute score → status + notify → tag).
        self::recalcGroup($chatId, $halflife);
        self::applyElderStatus($chatId, $threshold);
        self::applyElderTags($chatId, $threshold, (string) ($group['elder_title'] ?? ''));

        Logger::info('ScoreManager: elder appointed manually', [
            'chat_id' => $chatId, 'user' => $userId, 'weekly' => $weekly, 'messages' => $nHist + $recent,
        ]);
        return 'ok';
    }

    /** Current decayed score of one member (same formula as recalcGroup). */
    private static function userScore(int $chatId, int $userId, float $lambda): float
    {
        return (float) DB::fetchColumn(
            "SELECT COALESCE(SUM(EXP(-? * TIMESTAMPDIFF(SECOND, sent_at, NOW()) / 86400)), 0)
               FROM " . DB::table('messages') . " WHERE chat_id = ? AND user_id = ?",
            [$lambda, $chatId, $userId]
        );
    }

    /**
     * Insert fake message rows at the given ages (seconds before NOW). Text is never stored, so a
     * backfilled row is indistinguishable from a real one and decays the same way.
     *
     * @param list<int> $agesSeconds
     */
    private static function insertFakeMessages(int $chatId, int $userId, array $agesSeconds): void
    {
        if ($agesSeconds === []) {
            return;
        }
        $stmt = DB::pdo()->prepare(
            "INSERT INTO " . DB::table('messages') . " (chat_id, user_id, sent_at, reply_to_msg_id)
             VALUES (?, ?, NOW() - INTERVAL ? SECOND, NULL)"
        );
        foreach ($agesSeconds as $age) {
            $stmt->execute([$chatId, $userId, (int) $age]);
        }
    }

    /**
     * Activity stats for the elder simulator: "active" members and their rate (counted
     * messages per day) over a window of $windowDays. Active = posts at least once per
     * $perDays days → ≥ ceil(window/perDays) messages in the window. $excludeIds (e.g.
     * admins) are excluded. The list is sorted by rate (descending).
     *
     * @param array<int, int> $excludeIds
     * @return array{window:int, active_min:int, users: array<int, array{user_id:int, username:?string, msgs:int, rate:float}>}
     */
    public static function activityStats(int $chatId, array $excludeIds = [], int $windowDays = 30, int $perDays = 5): array
    {
        $windowDays = max(1, $windowDays);
        $activeMin  = (int) max(1, (int) ceil($windowDays / max(1, $perDays)));

        $m = DB::table('messages');
        $p = DB::table('participants');
        $rows = DB::fetchAll(
            "SELECT m.user_id, p.username, COUNT(*) AS msgs
               FROM {$m} m
               LEFT JOIN {$p} p ON p.chat_id = m.chat_id AND p.user_id = m.user_id
              WHERE m.chat_id = ? AND m.sent_at > NOW() - INTERVAL ? DAY
              GROUP BY m.user_id, p.username
             HAVING msgs >= ?
              ORDER BY msgs DESC",
            [$chatId, $windowDays, $activeMin]
        );

        $exclude = array_flip($excludeIds);
        $users   = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if (isset($exclude[$uid])) {
                continue;
            }
            $msgs = (int) $r['msgs'];
            $users[] = [
                'user_id'  => $uid,
                'username' => $r['username'] !== null ? (string) $r['username'] : null,
                'msgs'     => $msgs,
                'rate'     => $msgs / $windowDays,
            ];
        }

        return ['window' => $windowDays, 'active_min' => $activeMin, 'users' => $users];
    }

    /** Delete message metadata older than $days days (cron: data_ttl). Returns the number deleted. */
    public static function purgeOldMessages(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        return DB::run(
            "DELETE FROM " . DB::table('messages') . " WHERE sent_at < NOW() - INTERVAL ? DAY",
            [$days]
        )->rowCount();
    }
}
