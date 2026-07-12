<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * GroupManager — group lifecycle and participant accounting.
 *
 * The foundation under VoteManager: it provides group settings, participant data
 * (including joined_at and is_protected) and the admin-rights check.
 *
 * joined_at rule:
 *   - real join (a chat_member event after the bot was installed) → NOW();
 *   - old-timer (first seen lazily, no row → present before the bot) → the group's added_at.
 */
final class GroupManager
{
    /** "Ancient" date for old-timers (present before the bot) — grants full tenure at once. */
    private const ANCIENT_JOIN = '2000-01-01 00:00:00';

    // =====================================================================
    // Group
    // =====================================================================

    /**
     * Return the group settings; create them from defaults if they don't exist yet.
     *
     * @return array<string, mixed>
     */
    public static function ensureGroup(int $chatId, ?string $title = null): array
    {
        $t = DB::table('groups');

        $row = DB::fetch("SELECT * FROM {$t} WHERE chat_id = ?", [$chatId]);
        if ($row !== null) {
            return $row;
        }

        // Create from config/defaults.php (the 'group' section). The column list comes from
        // the default keys — so the INSERT never drifts from the schema/defaults.
        $defaults = (array) Config::value('defaults', 'group', []);
        $cols     = array_keys($defaults);
        $vals     = array_map(
            static fn(mixed $v): mixed => is_bool($v) ? (int) $v : $v,
            array_values($defaults)
        );

        $colList      = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        DB::run(
            "INSERT IGNORE INTO {$t} (chat_id, title, {$colList}, added_at, updated_at)
             VALUES (?, ?, {$placeholders}, NOW(), NOW())",
            array_merge([$chatId, $title], $vals)
        );

        Logger::info('GroupManager: group created', ['chat_id' => $chatId, 'title' => $title]);

        $row = DB::fetch("SELECT * FROM {$t} WHERE chat_id = ?", [$chatId]);
        return $row ?? [];
    }

    /**
     * Return the group settings or null if it doesn't exist (without creating it).
     *
     * @return array<string, mixed>|null
     */
    public static function getGroup(int $chatId): ?array
    {
        $t = DB::table('groups');
        return DB::fetch("SELECT * FROM {$t} WHERE chat_id = ?", [$chatId]);
    }

    // =====================================================================
    // Participants
    // =====================================================================

    /**
     * Ensure a participant row exists and return it.
     * If there's no row, the participant was in the group BEFORE the bot (an "old-timer"):
     * joined_at = the group's added_at, msg_count = 0, score = 0.
     *
     * @return array<string, mixed>
     */
    public static function ensureParticipant(int $chatId, int $userId, ?string $username = null): array
    {
        $t = DB::table('participants');

        $row = DB::fetch(
            "SELECT * FROM {$t} WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        if ($row !== null) {
            return $row;
        }

        self::ensureGroup($chatId); // make sure the group exists

        // Old-timer (present before the bot): joined_at far in the past → full tenure at once
        // ("always been here"). Members who join AFTER the bot get joined_at = NOW() (see onJoin).
        DB::run(
            "INSERT IGNORE INTO {$t} (chat_id, user_id, username, joined_at, score, msg_count, is_protected)
             VALUES (?, ?, ?, ?, 0, 0, 0)",
            [$chatId, $userId, $username, self::ANCIENT_JOIN]
        );
        Logger::debug('GroupManager: old-timer participant lazily created', [
            'chat_id' => $chatId, 'user' => $userId, 'joined_at' => self::ANCIENT_JOIN,
        ]);

        $row = DB::fetch(
            "SELECT * FROM {$t} WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return $row ?? [];
    }

    /**
     * A real member join (chat_member event).
     * Checks a banned user's re-entry (reentry_until) → auto-kick.
     *
     * @param array<string, mixed> $user the Telegram user object
     */
    public static function onJoin(int $chatId, array $user): void
    {
        $group = self::ensureGroup($chatId);

        $userId   = (int) ($user['id'] ?? 0);
        $username = $user['username'] ?? null;
        if ($userId === 0) {
            return;
        }

        $t = DB::table('participants');

        // A banned user re-entering within the reentry_until window → kick again
        // (only if the reentry_autokick setting is on; otherwise "admin is king").
        if ((int) ($group['reentry_autokick'] ?? 1) === 1) {
            $stillBanned = DB::fetchColumn(
                "SELECT 1 FROM {$t}
                 WHERE chat_id = ? AND user_id = ? AND reentry_until IS NOT NULL AND reentry_until > NOW()",
                [$chatId, $userId]
            );
            if ($stillBanned) {
                Bot::call('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
                Logger::info('GroupManager: banned user re-entered — auto-kick', [
                    'chat_id' => $chatId, 'user' => $userId,
                ]);
                return;
            }
        }

        // New member → joined_at = NOW(). If the row already exists — don't touch joined_at
        // (tenure is preserved), only update the username.
        DB::run(
            "INSERT INTO {$t} (chat_id, user_id, username, joined_at, score, msg_count, is_protected)
             VALUES (?, ?, ?, NOW(), 0, 0, 0)
             ON DUPLICATE KEY UPDATE username = VALUES(username)",
            [$chatId, $userId, $username]
        );
        Logger::debug('GroupManager: member joined', ['chat_id' => $chatId, 'user' => $userId]);
    }

    /**
     * A member left the group (chat_member event). We keep their data
     * (history/tenure on return), and update the username if present.
     *
     * @param array<string, mixed> $user
     */
    public static function onLeave(int $chatId, array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        $username = $user['username'] ?? null;
        if ($username !== null) {
            $t = DB::table('participants');
            DB::run(
                "UPDATE {$t} SET username = ? WHERE chat_id = ? AND user_id = ?",
                [$username, $chatId, $userId]
            );
        }
        Logger::debug('GroupManager: member left', ['chat_id' => $chatId, 'user' => $userId]);
    }

    /**
     * A member was banned (by our vote or manually by an admin).
     * Sets banned_at and the re-entry window reentry_until.
     */
    public static function onMemberBanned(int $chatId, int $userId): void
    {
        $group = self::getGroup($chatId);
        if ($group === null) {
            return;
        }
        self::ensureParticipant($chatId, $userId);

        $hours = (int) ($group['reentry_ban_hours'] ?? 0);
        $t = DB::table('participants');
        DB::run(
            "UPDATE {$t}
                SET banned_at = NOW(), reentry_until = NOW() + INTERVAL ? HOUR
              WHERE chat_id = ? AND user_id = ?",
            [$hours, $chatId, $userId]
        );
        Logger::info('GroupManager: member banned', [
            'chat_id' => $chatId, 'user' => $userId, 'reentry_hours' => $hours,
        ]);
    }

    // =====================================================================
    // Bot lifecycle in the group
    // =====================================================================

    /**
     * The bot was added to the group (my_chat_member). Create the group from defaults.
     *
     * @param array<string, mixed> $cm the my_chat_member object
     */
    public static function onBotAdded(int $chatId, array $cm): void
    {
        $title  = $cm['chat']['title'] ?? null;
        $from   = $cm['from'] ?? [];
        $fromId = (int) ($from['id'] ?? 0);

        // A basic-group → supergroup upgrade lands here too (migrateChat moved the row to the new
        // chat_id and Telegram sent a left→admin my_chat_member). We must NOT re-onboard or run the
        // owner-check on an upgrade — its `from` may be a non-owner/service. Distinguish it: an
        // upgrade is "the group was still ACTIVE"; a genuine re-add is "the bot had been removed"
        // (onBotRemoved set is_active = 0). Read is_active BEFORE we flip it on.
        $existing  = self::getGroup($chatId);
        $existed   = $existing !== null;
        $wasActive = $existed && (int) ($existing['is_active'] ?? 0) === 1;
        if ($existed && $wasActive) {
            return; // upgrade / redundant event — stay silent
        }

        $group = self::ensureGroup($chatId, $title);
        self::setActive($chatId, true);
        Telemetry::record('bot_added', $chatId);
        Logger::info('GroupManager: bot added to group', ['chat_id' => $chatId, 'existed' => $existed]);

        // A migration ALWAYS produces a supergroup, so a SUPERGROUP add might be a genuine add OR a
        // basic→supergroup upgrade whose migration hasn't merged yet — we can't tell now. DEFER the
        // whole first onboarding pass to the cron (onboarding_pending = 1): by its next tick a
        // migration, if any, has merged and wiped this row, so an upgrade produces no duplicate
        // onboarding. An add to a BASIC group is never a migration in progress → onboard immediately.
        if ((string) ($cm['chat']['type'] ?? '') === 'supergroup') {
            DB::run(
                "UPDATE " . DB::table('groups')
                . " SET onboarding_pending = 1, onboarding_adder = ?, onboarding_at = NOW(), onboarding_hint_msg_id = NULL WHERE chat_id = ?",
                [$fromId, $chatId]
            );
            return;
        }

        self::onboardFirstPass($chatId, $group, $fromId);
    }

    /**
     * The first onboarding pass for a freshly connected group. Runs synchronously for a basic-group
     * add, or from the cron for a deferred supergroup add. Owner-only policy: we onboard normally
     * only for a CONFIRMED owner ('creator'); for a confirmed non-owner — OR a failed lookup (from
     * != 0, status unknown) — we DEFER an owner-check/leave to the cron. Unknown adder (from == 0)
     * is not deferred; we never leave on that alone.
     *
     * @param array<string, mixed> $group a groups row
     */
    private static function onboardFirstPass(int $chatId, array $group, int $fromId): void
    {
        if ($fromId !== 0) {
            $m = self::getMember($chatId, $fromId);
            if ($m === null || (string) ($m['status'] ?? '') !== 'creator') {
                self::onboardingBeginDeferredLeave($chatId, $group, $fromId);
                return;
            }
        }
        // Confirmed owner, or unknown adder → onboard on the LIVE owner and the bot rights.
        self::onboardStart($chatId, $group);
    }

    /**
     * A non-owner (or an adder we couldn't verify) connected the bot: arm a deferred owner-check
     * (the cron leaves in 10 min unless the OWNER sanctions the bot by promoting it). Meanwhile tell
     * the owner they need to grant the ban right — in their DM if we can reach them, else in the group.
     *
     * @param array<string, mixed> $group a groups row
     */
    private static function onboardingBeginDeferredLeave(int $chatId, array $group, int $adderId): void
    {
        $ownerId   = self::ownerId($chatId);
        $title     = (string) ($group['title'] ?? $chatId);
        $hintMsgId = null;

        // If we can't even determine the owner (ownerId == 0), the group is momentarily
        // unqueryable — typically a supergroup upgrade still settling. Send NOTHING now (a group
        // post here would flicker and get wiped by migrateChat); just arm the marker and let the
        // cron act once the group is stable.
        if ($ownerId !== 0 && self::hasDm($ownerId)) {
            Bot::call('sendMessage', [
                'chat_id' => $ownerId,
                'text'    => Lang::get('group_rights_missing', self::userLangOrDefault($ownerId), ['title' => $title]),
            ]);
        } elseif ($ownerId !== 0) {
            $res = Bot::call('sendMessage', [
                'chat_id' => $chatId,
                'text'    => Lang::get('group_setup_hint', (string) ($group['lang'] ?? 'ru'), ['bot' => (string) Config::value('bot', 'BOT_USERNAME', '')]),
            ]);
            if (is_array($res) && isset($res['message_id'])) {
                $hintMsgId = (int) $res['message_id'];
            }
        }

        DB::run(
            "UPDATE " . DB::table('groups')
            . " SET onboarding_at = NOW(), onboarding_adder = ?, onboarding_hint_msg_id = ? WHERE chat_id = ?",
            [$adderId, $hintMsgId, $chatId]
        );
        Logger::info('GroupManager: non-owner add — deferred owner-check', ['chat_id' => $chatId, 'by' => $adderId]);
    }

    /**
     * Start (or refresh) the onboarding for a freshly connected group: confirm to the owner if we
     * can DM them, and — if the bot has no ban right — enter the "waiting for the ban right" phase
     * (the cron then handles the +1 min in-group hint and the +10 min follow-up).
     *
     * @param array<string, mixed> $group a groups row
     */
    private static function onboardStart(int $chatId, array $group): void
    {
        $ownerId    = self::ownerId($chatId);
        $dialogOpen = $ownerId !== 0 && self::hasDm($ownerId);
        $title      = (string) ($group['title'] ?? $chatId);

        if ($dialogOpen) {
            // Confirm in the DM + refresh the owner's group list (which shows ⚠️/⚙️ per group).
            Bot::call('sendMessage', [
                'chat_id' => $ownerId,
                'text'    => Lang::get('group_connected', self::userLangOrDefault($ownerId), ['title' => $title]),
            ]);
            self::sendDialog(['id' => $ownerId], false);
        }

        if (self::botHasBanRight($chatId)) {
            self::onboardingClear($chatId); // already fully working
            Telemetry::record('bot_admin_granted', $chatId); // added straight as admin with the ban right
            return;
        }
        // No ban right yet → wait for it (adder = NULL marks the "waiting for rights" phase).
        DB::run(
            "UPDATE " . DB::table('groups')
            . " SET onboarding_at = NOW(), onboarding_adder = NULL, onboarding_hint_msg_id = NULL WHERE chat_id = ?",
            [$chatId]
        );
    }

    /**
     * The bot was removed/kicked from the group (my_chat_member).
     * We keep the group's data (a re-add reuses the settings).
     */
    public static function onBotRemoved(int $chatId): void
    {
        // Keep the data (a re-add reuses settings/history; it's also exportable) but flag the
        // group inactive so we stop live-querying and listing it.
        self::setActive($chatId, false);
        // Telemetry: a human kick/removal — but our OWN self-leave (onboardingCheckLeave → leaveChat)
        // also surfaces here as a left event, so skip the duplicate if we just recorded bot_left.
        if (!Telemetry::recordedRecently('bot_left', $chatId, 120)) {
            Telemetry::record('bot_removed', $chatId);
        }
        Logger::info('GroupManager: bot removed from group (kept, marked inactive)', ['chat_id' => $chatId]);
    }

    /**
     * Migrate data on a basic-group → supergroup upgrade: chat_id changes (old → new).
     * Telegram sends a service message with migrate_to/from_chat_id.
     * Idempotent: only runs while the old group still exists.
     */
    public static function migrateChat(int $oldId, int $newId): void
    {
        if ($oldId === 0 || $newId === 0 || $oldId === $newId) {
            return;
        }
        $groups = DB::table('groups');
        if (!DB::fetchColumn("SELECT 1 FROM {$groups} WHERE chat_id = ?", [$oldId])) {
            return; // already migrated, or the old group is gone
        }

        // Remove the freshly created default row for the new group (if one appeared) —
        // we move the data (with settings) over from the old one.
        DB::run("DELETE FROM {$groups} WHERE chat_id = ?", [$newId]);

        foreach (['groups', 'participants', 'messages', 'votes', 'suspects', 'bot_messages'] as $tbl) {
            try {
                DB::run("UPDATE " . DB::table($tbl) . " SET chat_id = ? WHERE chat_id = ?", [$newId, $oldId]);
            } catch (Throwable $e) {
                Logger::error('GroupManager: chat_id migration error', $e, ['table' => $tbl, 'old' => $oldId, 'new' => $newId]);
            }
        }
        Logger::info('GroupManager: group migrated to a supergroup', ['old' => $oldId, 'new' => $newId]);
    }

    // =====================================================================
    // Admin rights
    // =====================================================================

    /**
     * getChatMember with self-healing, used by all live membership checks. Failures are expected
     * (the bot may have left a group we still have a row for), so they're logged quietly. On a
     * "group upgraded to a supergroup" error we migrate our data to the new chat_id; on a 403
     * (bot kicked / not a member) we mark the group inactive so we stop querying it.
     *
     * @return array<string, mixed>|null the ChatMember object, or null on failure
     */
    private static function getMember(int $chatId, int $userId): ?array
    {
        $res = Bot::call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId], true);
        if (is_array($res)) {
            return $res;
        }
        $newId = Bot::migrateToChatId();
        if ($newId !== null && $newId !== $chatId) {
            self::migrateChat($chatId, $newId); // basic → supergroup: collapse to the new id
        } elseif (Bot::lastErrorCode() === 403) {
            self::setActive($chatId, false);    // bot is no longer in this chat
        }
        return null;
    }

    /** Flip a group's connected flag (kept even when inactive — data is preserved). */
    private static function setActive(int $chatId, bool $active): void
    {
        DB::run(
            "UPDATE " . DB::table('groups') . " SET is_active = ? WHERE chat_id = ?",
            [$active ? 1 : 0, $chatId]
        );
    }

    /**
     * Live admin-rights check via getChatMember
     * (no admin table is kept — rights are checked on the fly).
     */
    public static function isAdmin(int $chatId, int $userId): bool
    {
        $m = self::getMember($chatId, $userId);
        $status = $m !== null ? (string) ($m['status'] ?? '') : '';
        return $status === 'administrator' || $status === 'creator';
    }

    /** Whether the user is the group owner (creator). Only the owner grants/revokes manage rights. */
    public static function isOwner(int $chatId, int $userId): bool
    {
        $m = self::getMember($chatId, $userId);
        return ($m !== null ? (string) ($m['status'] ?? '') : '') === 'creator';
    }

    /**
     * Whether the user may change the bot's settings (settings page + simulator):
     * the owner always may; another admin only if granted can_manage. A demoted admin
     * loses it (must still be an admin). One getChatMember call.
     */
    public static function canManage(int $chatId, int $userId): bool
    {
        $m = self::getMember($chatId, $userId);
        $status = $m !== null ? (string) ($m['status'] ?? '') : '';
        if ($status === 'creator') {
            return true;
        }
        if ($status !== 'administrator') {
            return false;
        }
        $flag = DB::fetchColumn(
            "SELECT can_manage FROM " . DB::table('participants') . " WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return (int) $flag === 1;
    }

    /** Grant/revoke the "settings manager" right for a user (owner-only, admin-only — enforced by the caller). */
    public static function setManager(int $chatId, int $userId, bool $grant): void
    {
        self::ensureParticipant($chatId, $userId);
        DB::run(
            "UPDATE " . DB::table('participants') . " SET can_manage = ? WHERE chat_id = ? AND user_id = ?",
            [$grant ? 1 : 0, $chatId, $userId]
        );
        Logger::info('GroupManager: manager right changed', ['chat_id' => $chatId, 'user' => $userId, 'grant' => $grant]);
    }

    /** The bot's own Telegram user id (getMe, cached per request). 0 if unavailable. */
    private static function botId(): int
    {
        static $id = null;
        if ($id === null) {
            $me = Bot::call('getMe');
            $id = is_array($me) ? (int) ($me['id'] ?? 0) : 0;
        }
        return $id;
    }

    /**
     * Whether the bot is an admin WITH the ban right in the group — i.e. it can actually enforce
     * a vote. Used to nudge the owner to promote the bot during onboarding. If it can't be
     * checked (API error), returns true so we don't show a false warning.
     */
    public static function botCanEnforce(int $chatId): bool
    {
        $bid = self::botId();
        if ($bid === 0) {
            return true;
        }
        $m = self::getMember($chatId, $bid);
        if ($m === null) {
            return true; // couldn't check (the group is being deactivated/migrated) — don't nag
        }
        if (($m['status'] ?? '') !== 'administrator') {
            return false;
        }
        return !empty($m['can_restrict_members']);
    }

    /**
     * Strict "does the bot have the ban right" check for the onboarding flow: unlike botCanEnforce
     * (which returns true when it can't check, to avoid a false ⚠️), this returns FALSE on any
     * uncertainty — so onboarding keeps waiting rather than falsely declaring success.
     */
    private static function botHasBanRight(int $chatId): bool
    {
        $bid = self::botId();
        if ($bid === 0) {
            return false;
        }
        $m = self::getMember($chatId, $bid);
        if ($m === null) {
            return false; // uncertain → treat as "no right yet"
        }
        return ($m['status'] ?? '') === 'administrator' && !empty($m['can_restrict_members']);
    }

    /** The group's owner (creator) user_id via a live getChatAdministrators call, or 0. */
    public static function ownerId(int $chatId): int
    {
        $admins = Bot::call('getChatAdministrators', ['chat_id' => $chatId], true);
        if (is_array($admins)) {
            foreach ($admins as $a) {
                if (($a['status'] ?? '') === 'creator') {
                    return (int) ($a['user']['id'] ?? 0);
                }
            }
        }
        return 0;
    }

    /**
     * The bot was promoted/demoted (ban right granted/revoked) — reconcile the onboarding state.
     * Called from my_chat_member with $byUserId = who made the change.
     *
     * A group awaiting owner-verification (a non-owner added it) is sanctioned ONLY when the OWNER
     * promotes the bot to admin — then the deferred leave is cancelled. Any other actor's change (or
     * a demotion) keeps the deferred leave.
     */
    public static function onBotRightsChanged(int $chatId, int $byUserId = 0): void
    {
        $group = self::getGroup($chatId);
        if ($group === null || (int) ($group['is_active'] ?? 0) !== 1) {
            return;
        }

        // One live read of the bot's own membership: is it an admin, and does it have the ban right.
        $bm          = self::getMember($chatId, self::botId());
        $botAdmin    = is_array($bm) && ($bm['status'] ?? '') === 'administrator';
        $hasBanRight = $botAdmin && !empty($bm['can_restrict_members']);

        if (($group['onboarding_adder'] ?? null) !== null) {
            // Only the OWNER promoting the bot to admin sanctions it and cancels the deferred leave.
            if ($botAdmin && $byUserId !== 0 && self::isOwner($chatId, $byUserId)) {
                DB::run("UPDATE " . DB::table('groups') . " SET onboarding_adder = NULL WHERE chat_id = ?", [$chatId]);
                $group['onboarding_adder'] = null;
                Logger::info('GroupManager: owner sanctioned a non-owner add', ['chat_id' => $chatId, 'by' => $byUserId]);
                // fall through to normal rights handling
            } else {
                // Not a sanction (non-owner action, or a demotion). Keep the deferred leave; if the
                // bot lacks the ban right, nudge the owner (if reachable) to grant it.
                if (!$hasBanRight) {
                    $ownerId = self::ownerId($chatId);
                    if ($ownerId !== 0 && self::hasDm($ownerId)) {
                        Bot::call('sendMessage', [
                            'chat_id' => $ownerId,
                            'text'    => Lang::get('group_rights_missing', self::userLangOrDefault($ownerId), ['title' => (string) ($group['title'] ?? $chatId)]),
                        ]);
                    }
                }
                return;
            }
        }

        $waiting = ($group['onboarding_at'] ?? null) !== null;

        if ($hasBanRight) {
            if ($waiting) {
                self::onboardingRightsObtained($chatId, $group);
            }
            return;
        }

        // No ban right. If we weren't already waiting, it was just revoked → re-enter the wait and
        // tell the owner right away if we can reach them (else the cron posts an in-group hint).
        if (!$waiting) {
            DB::run(
                "UPDATE " . DB::table('groups')
                . " SET onboarding_at = NOW(), onboarding_adder = NULL, onboarding_hint_msg_id = NULL WHERE chat_id = ?",
                [$chatId]
            );
            $ownerId = self::ownerId($chatId);
            if ($ownerId !== 0 && self::hasDm($ownerId)) {
                Bot::call('sendMessage', [
                    'chat_id' => $ownerId,
                    'text'    => Lang::get('group_rights_missing', self::userLangOrDefault($ownerId), ['title' => (string) ($group['title'] ?? $chatId)]),
                ]);
            }
            Logger::info('GroupManager: ban right revoked → waiting', ['chat_id' => $chatId]);
        }
    }

    /** Ban right obtained during onboarding: drop the in-group hint, confirm to the owner, clear. */
    private static function onboardingRightsObtained(int $chatId, array $group): void
    {
        $hintId = (int) ($group['onboarding_hint_msg_id'] ?? 0);
        if ($hintId > 0) {
            Bot::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $hintId], true);
        }
        $ownerId = self::ownerId($chatId);
        if ($ownerId !== 0 && self::hasDm($ownerId)) {
            Bot::call('sendMessage', [
                'chat_id' => $ownerId,
                'text'    => Lang::get('group_rights_ok', self::userLangOrDefault($ownerId), ['title' => (string) ($group['title'] ?? $chatId)]),
            ]);
        }
        self::onboardingClear($chatId);
        Telemetry::record('bot_admin_granted', $chatId);
        Logger::info('GroupManager: ban right obtained', ['chat_id' => $chatId]);
    }

    /** Clear all onboarding state for a group. */
    private static function onboardingClear(int $chatId): void
    {
        DB::run(
            "UPDATE " . DB::table('groups')
            . " SET onboarding_at = NULL, onboarding_adder = NULL, onboarding_hint_msg_id = NULL WHERE chat_id = ?",
            [$chatId]
        );
    }

    /**
     * Groups where the given user is an administrator (live getChatMember check for each
     * connected group). Used by the web panel and onboarding.
     *
     * @return array<int, array{chat_id:int, title:?string}>
     */
    public static function groupsForAdmin(int $userId): array
    {
        $rows = DB::fetchAll(
            "SELECT chat_id, title FROM " . DB::table('groups') . " WHERE is_active = 1 ORDER BY (title IS NULL), title"
        );
        $out = [];
        foreach ($rows as $g) {
            $cid = (int) $g['chat_id'];
            if (self::isAdmin($cid, $userId)) {
                $out[] = ['chat_id' => $cid, 'title' => isset($g['title']) ? (string) $g['title'] : null];
            }
        }
        return $out;
    }

    /**
     * Every group the bot has ever been connected to — for the hoster overview page. Includes
     * inactive groups (bot removed) so the operator sees them too. "last_activity" is the latest
     * of: a counted message, a vote start, or when the bot was added — enough to tell "went quiet
     * a year ago" from "yesterday". Ordered active-first, then most-recently-active. DB only (no
     * live API calls); the admin roster is fetched live per selected group by the panel.
     *
     * @return array<int, array{chat_id:int, title:?string, is_active:int, last_activity:string}>
     */
    public static function hosterGroups(): array
    {
        $groups = DB::table('groups');
        $msgs   = DB::table('messages');
        $votes  = DB::table('votes');
        $rows = DB::fetchAll(
            "SELECT g.chat_id, g.title, g.is_active,
                    GREATEST(
                        COALESCE((SELECT MAX(m.sent_at)    FROM $msgs  m WHERE m.chat_id = g.chat_id), g.added_at),
                        COALESCE((SELECT MAX(v.started_at)  FROM $votes v WHERE v.chat_id = g.chat_id), g.added_at),
                        g.added_at
                    ) AS last_activity
             FROM $groups g
             ORDER BY g.is_active DESC, last_activity DESC"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'chat_id'       => (int) $r['chat_id'],
                'title'         => isset($r['title']) ? (string) $r['title'] : null,
                'is_active'     => (int) $r['is_active'],
                'last_activity' => (string) $r['last_activity'],
            ];
        }
        return $out;
    }

    // =====================================================================
    // Participant actions (by-id) — reused by the web panel.
    // =====================================================================

    /** Protect a participant from votes (is_protected). */
    public static function setProtectionById(int $chatId, int $userId, bool $protect): void
    {
        self::ensureParticipant($chatId, $userId);
        DB::run(
            "UPDATE " . DB::table('participants') . " SET is_protected = ? WHERE chat_id = ? AND user_id = ?",
            [$protect ? 1 : 0, $chatId, $userId]
        );
        Logger::info('GroupManager: protection', ['chat_id' => $chatId, 'user' => $userId, 'protected' => $protect]);
    }

    /** Lift a ban: Telegram unbanChatMember + clear banned_at/reentry_until. */
    public static function unbanById(int $chatId, int $userId): void
    {
        Bot::call('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
        DB::run(
            "UPDATE " . DB::table('participants') . " SET banned_at = NULL, reentry_until = NULL WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        Logger::info('GroupManager: unban', ['chat_id' => $chatId, 'user' => $userId]);
    }

    /**
     * Resolve a "@username or numeric id" query to candidate participants of THIS group (the Bot
     * API can't look up a user by @username, so we match our own stored data). A numeric query is
     * matched as a user_id; otherwise as a username (case-insensitive, leading @ ignored). Usually
     * one match; more than one is possible if a username was reused across accounts over time.
     *
     * @return array<int, array{user_id:int, username:string}>
     */
    public static function findParticipantsByQuery(int $chatId, string $query): array
    {
        $query = ltrim(trim($query), '@');
        if ($query === '') {
            return [];
        }
        $t = DB::table('participants');
        // Telegram usernames must contain a letter, so an all-digit query is a user_id.
        if (ctype_digit($query)) {
            $rows = DB::fetchAll(
                "SELECT user_id, username FROM {$t} WHERE chat_id = ? AND user_id = ?",
                [$chatId, (int) $query]
            );
            if ($rows !== []) {
                return $rows;
            }
        }
        return DB::fetchAll(
            "SELECT user_id, username FROM {$t} WHERE chat_id = ? AND LOWER(username) = LOWER(?)",
            [$chatId, $query]
        );
    }

    /**
     * Erase one user's data from a group (privacy request, run by an owner/manager). Fully deletes
     * data that belongs to the user alone; only ANONYMIZES them inside shared vote rows so other
     * participants' voting history and everyone's elder scores (which are computed from messages,
     * not votes) stay intact. Global per-user state (users: DM language/opt-in, spans other groups)
     * is intentionally left untouched. Returns per-target row counts.
     *
     * @return array<string, int>
     */
    public static function eraseUserData(int $chatId, int $userId): array
    {
        if ($userId === 0) {
            return [];
        }
        // Close any active vote involving this user first, so anonymizing them below can't disturb
        // a live vote (it would otherwise lose its target/initiator mid-flight).
        VoteManager::cancelActiveForUser($chatId, $userId);

        $counts = [];
        DB::begin();
        try {
            $counts['messages'] = DB::run(
                "DELETE FROM " . DB::table('messages') . " WHERE chat_id = ? AND user_id = ?",
                [$chatId, $userId]
            )->rowCount();

            // The user's OWN cast votes (vote_records has no chat_id → scope via this chat's votes).
            $counts['votes_cast'] = DB::run(
                "DELETE vr FROM " . DB::table('vote_records') . " vr
                   JOIN " . DB::table('votes') . " v ON v.id = vr.vote_id
                  WHERE v.chat_id = ? AND vr.voter_id = ?",
                [$chatId, $userId]
            )->rowCount();

            $counts['suspects'] = DB::run(
                "DELETE FROM " . DB::table('suspects') . " WHERE chat_id = ? AND user_id = ?",
                [$chatId, $userId]
            )->rowCount();

            // Anonymize the user inside shared votes (keep the vote + other voters' records).
            $counts['as_target'] = DB::run(
                "UPDATE " . DB::table('votes') . "
                    SET target_id = 0, trigger_text = NULL, trigger_msg_id = NULL, trigger_date = NULL
                  WHERE chat_id = ? AND target_id = ?",
                [$chatId, $userId]
            )->rowCount();
            $counts['as_initiator'] = DB::run(
                "UPDATE " . DB::table('votes') . " SET initiator_id = 0 WHERE chat_id = ? AND initiator_id = ?",
                [$chatId, $userId]
            )->rowCount();

            // Notification-action context (DM reply commands) referencing this user.
            $counts['notify_actions'] = DB::run(
                "DELETE FROM " . DB::table('notify_actions') . " WHERE chat_id = ? AND (user_id = ? OR target_id = ?)",
                [$chatId, $userId, $userId]
            )->rowCount();

            $counts['participant'] = DB::run(
                "DELETE FROM " . DB::table('participants') . " WHERE chat_id = ? AND user_id = ?",
                [$chatId, $userId]
            )->rowCount();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error('GroupManager: eraseUserData failed', $e, ['chat_id' => $chatId, 'user' => $userId]);
            throw $e;
        }

        Logger::info('GroupManager: user data erased', array_merge(['chat_id' => $chatId, 'user' => $userId], $counts));
        return $counts;
    }

    /**
     * Participants list for the panel: search (username/id), sort, pagination.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function listParticipants(
        int $chatId,
        string $search = '',
        string $sortSpec = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        $orderBy = self::buildOrderBy($sortSpec);
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $t      = DB::table('participants');
        $where  = 'chat_id = ?';
        $params = [$chatId];
        if ($search !== '') {
            $where   .= ' AND (username LIKE ? OR CAST(user_id AS CHAR) LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $total = (int) DB::fetchColumn("SELECT COUNT(*) FROM {$t} WHERE {$where}", $params);
        $rows  = DB::fetchAll(
            "SELECT user_id, username, joined_at, score, msg_count, is_protected, banned_at, reentry_until, can_manage, is_elder
               FROM {$t} WHERE {$where}
              ORDER BY {$orderBy}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Build an ORDER BY from a multi-sort spec "col:dir,col:dir" (fields whitelisted,
     * directions validated). Always appends ", id ASC" for stable pagination.
     * Empty/garbage → the default sort (joined_at DESC).
     */
    private static function buildOrderBy(string $sortSpec): string
    {
        $allowed = ['username', 'joined_at', 'score', 'msg_count', 'is_protected', 'banned_at'];
        $orders  = [];
        $seen    = [];
        foreach (explode(',', $sortSpec) as $part) {
            $bits = explode(':', trim($part));
            $col  = trim($bits[0] ?? '');
            if ($col === '' || !in_array($col, $allowed, true) || isset($seen[$col])) {
                continue;
            }
            $orders[] = $col . ' ' . (strtolower(trim($bits[1] ?? 'asc')) === 'asc' ? 'ASC' : 'DESC');
            $seen[$col] = true;
        }
        if ($orders === []) {
            $orders[] = 'joined_at DESC';
        }
        return implode(', ', $orders) . ', id ASC';
    }

    /**
     * Vote journal for the panel: search, status filter, sort, pagination. Target/initiator
     * names are pulled from participants; "for/against" sums come from vote_records.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function listVotes(
        int $chatId,
        string $search = '',
        string $sortSpec = '',
        int $page = 1,
        int $perPage = 25,
        string $status = ''
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $v  = DB::table('votes');
        $p  = DB::table('participants');
        $vr = DB::table('vote_records');

        $joins = " FROM {$v} v
            LEFT JOIN {$p} pt ON pt.chat_id = v.chat_id AND pt.user_id = v.target_id
            LEFT JOIN {$p} pi ON pi.chat_id = v.chat_id AND pi.user_id = v.initiator_id";

        $where  = 'WHERE v.chat_id = ?';
        $params = [$chatId];
        if (in_array($status, ['active', 'banned', 'declined', 'expired', 'cancelled'], true)) {
            $where   .= ' AND v.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= ' AND (CAST(v.target_id AS CHAR) LIKE ? OR CAST(v.initiator_id AS CHAR) LIKE ?'
                    . ' OR pt.username LIKE ? OR pi.username LIKE ? OR v.trigger_text LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $total = (int) DB::fetchColumn("SELECT COUNT(*) {$joins} {$where}", $params);

        $orderBy = self::buildVoteOrderBy($sortSpec);
        $rows = DB::fetchAll(
            "SELECT v.id, v.target_id, v.initiator_id, v.status, v.started_at, v.finished_at, v.trigger_text,
                    pt.username AS target_username, pi.username AS initiator_username,
                    pt.banned_at AS target_banned,
                    (SELECT COALESCE(SUM(weight), 0) FROM {$vr} WHERE vote_id = v.id AND direction = 'for')     AS for_sum,
                    (SELECT COALESCE(SUM(weight), 0) FROM {$vr} WHERE vote_id = v.id AND direction = 'against') AS against_sum
             {$joins} {$where}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** ORDER BY for the vote journal (whitelist alias => SQL expression). */
    private static function buildVoteOrderBy(string $sortSpec): string
    {
        $allowed = [
            'started_at'        => 'v.started_at',
            'finished_at'       => 'v.finished_at',
            'status'            => 'v.status',
            'target_username'   => 'pt.username',
            'initiator_username' => 'pi.username',
            'for_sum'           => 'for_sum',
            'against_sum'       => 'against_sum',
        ];
        $orders = [];
        $seen   = [];
        foreach (explode(',', $sortSpec) as $part) {
            $bits = explode(':', trim($part));
            $col  = trim($bits[0] ?? '');
            if ($col === '' || !isset($allowed[$col]) || isset($seen[$col])) {
                continue;
            }
            $orders[] = $allowed[$col] . ' ' . (strtolower(trim($bits[1] ?? 'asc')) === 'asc' ? 'ASC' : 'DESC');
            $seen[$col] = true;
        }
        if ($orders === []) {
            $orders[] = 'v.started_at DESC';
        }
        return implode(', ', $orders) . ', v.id ASC';
    }

    // =====================================================================
    // Group settings (web panel): field schema + validated saving.
    // =====================================================================

    /**
     * Schema of the editable group settings (order = order in the form).
     * Key names = groups table columns (NOT user input → safe to inline into UPDATE).
     * type: int|decimal|bool|select|text. full=true — the field only applies in full mode.
     * options for select: value => label (a Lang key if options_lang=true, else a literal).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function settingsSchema(): array
    {
        return [
            'mode' => ['type' => 'select', 'section' => 'mode', 'options' => ['light' => 'mode_light', 'full' => 'mode_full'], 'options_lang' => true],

            'min_age_hours'        => ['type' => 'int', 'section' => 'voting', 'min' => 0],
            'min_messages'         => ['type' => 'int', 'section' => 'voting', 'min' => 0, 'full' => true],
            'min_msg_length'       => ['type' => 'int', 'section' => 'voting', 'min' => 0, 'full' => true],
            'msg_cooldown_minutes' => ['type' => 'int', 'section' => 'voting', 'min' => 0, 'full' => true],

            'ban_threshold'           => ['type' => 'decimal', 'section' => 'thresholds', 'min' => 0.01, 'step' => 0.5],
            'ban_decline_threshold'   => ['type' => 'decimal', 'section' => 'thresholds', 'min' => 0.01, 'step' => 0.5],
            'readonly_ratio'          => ['type' => 'decimal', 'section' => 'thresholds', 'min' => 0, 'max' => 1, 'step' => 0.05],
            'protected_ban_threshold' => ['type' => 'decimal', 'section' => 'thresholds', 'min' => 0, 'step' => 0.5],

            'T1_hours'          => ['type' => 'int', 'section' => 'timeouts', 'min' => 1],
            'T2_hours'          => ['type' => 'int', 'section' => 'timeouts', 'min' => 1],
            'cooldown_hours'    => ['type' => 'int', 'section' => 'timeouts', 'min' => 0],
            'reentry_ban_hours' => ['type' => 'int', 'section' => 'timeouts', 'min' => 0],
            'reentry_autokick'  => ['type' => 'bool', 'section' => 'timeouts'],

            'halflife_days'   => ['type' => 'int', 'section' => 'elder', 'min' => 1, 'full' => true],
            'elder_threshold' => ['type' => 'decimal', 'section' => 'elder', 'min' => 0, 'step' => 1, 'full' => true],
            'elder_weight'    => ['type' => 'decimal', 'section' => 'elder', 'min' => 1, 'step' => 0.5, 'full' => true],
            'elder_title'     => ['type' => 'text', 'section' => 'elder', 'maxlen' => 64, 'full' => true],

            'admin_instant_ban'      => ['type' => 'bool', 'section' => 'behavior'],
            'delete_trigger_message' => ['type' => 'bool', 'section' => 'behavior'],
            'delete_spam_on_ban'     => ['type' => 'bool', 'section' => 'behavior'],
            'show_full_list'         => ['type' => 'bool', 'section' => 'behavior'],
            'reveal_delay_seconds'   => ['type' => 'int', 'section' => 'behavior', 'min' => 0],
            'cleanup_delay_seconds'  => ['type' => 'int', 'section' => 'behavior', 'min' => 0],

            'lang' => ['type' => 'select', 'section' => 'lang', 'options' => Lang::available(), 'options_lang' => false],
        ];
    }

    /**
     * Cron (reentry_check): clear expired re-entry windows — where reentry_until is already
     * in the past, null it so that onJoin no longer treats the member as an auto-kick
     * candidate. Returns the number of affected rows.
     */
    public static function clearExpiredReentry(): int
    {
        return DB::run(
            "UPDATE " . DB::table('participants') . "
                SET reentry_until = NULL
              WHERE reentry_until IS NOT NULL AND reentry_until < NOW()"
        )->rowCount();
    }

    /** Save just the elder parameters (from the simulator): halflife_days + elder_threshold. */
    public static function saveElderParams(int $chatId, float $halflife, float $threshold): void
    {
        $halflife  = max(1, (int) round($halflife));
        $threshold = max(0.0, $threshold);
        DB::run(
            "UPDATE " . DB::table('groups') . " SET halflife_days = ?, elder_threshold = ?, updated_at = NOW() WHERE chat_id = ?",
            [$halflife, $threshold, $chatId]
        );
        Logger::info('GroupManager: elder parameters saved (simulator)', [
            'chat_id' => $chatId, 'halflife' => $halflife, 'threshold' => $threshold,
        ]);
    }

    /**
     * Save group settings from the form (validate/coerce per the schema).
     * Out-of-range values are clamped; an unknown select value skips the field.
     */
    public static function saveSettings(int $chatId, array $input): bool
    {
        $cols = [];
        $params = [];
        foreach (self::settingsSchema() as $key => $def) {
            $val = $input[$key] ?? null;
            switch ($def['type']) {
                case 'bool':
                    $val = isset($input[$key]) ? 1 : 0;
                    break;
                case 'int':
                    $val = (int) $val;
                    if (isset($def['min'])) { $val = max((int) $def['min'], $val); }
                    if (isset($def['max'])) { $val = min((int) $def['max'], $val); }
                    break;
                case 'decimal':
                    $val = (float) str_replace(',', '.', (string) $val);
                    if (isset($def['min'])) { $val = max((float) $def['min'], $val); }
                    if (isset($def['max'])) { $val = min((float) $def['max'], $val); }
                    break;
                case 'select':
                    if (!array_key_exists((string) $val, (array) $def['options'])) {
                        continue 2; // unknown value — leave the field untouched
                    }
                    $val = (string) $val;
                    break;
                case 'text':
                    $val = mb_substr(trim((string) $val), 0, (int) ($def['maxlen'] ?? 255));
                    break;
                default:
                    continue 2;
            }
            $cols[]   = "{$key} = ?";
            $params[] = $val;
        }
        if ($cols === []) {
            return false;
        }
        $params[] = $chatId;
        DB::run(
            "UPDATE " . DB::table('groups') . " SET " . implode(', ', $cols) . ", updated_at = NOW() WHERE chat_id = ?",
            $params
        );
        Logger::info('GroupManager: group settings updated', ['chat_id' => $chatId]);
        return true;
    }

    /** Send a temporary message (auto-deleted after $delaySeconds, or cleanup_delay by default). */
    private static function ephemeral(int $chatId, array $group, string $text, ?int $delaySeconds = null): void
    {
        $delay = $delaySeconds ?? (int) ($group['cleanup_delay_seconds'] ?? 60);
        $res = Bot::call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
        if (is_array($res) && isset($res['message_id'])) {
            DB::run(
                "INSERT INTO " . DB::table('bot_messages') . " (chat_id, message_id, delete_at)
                 VALUES (?, ?, NOW() + INTERVAL ? SECOND)",
                [$chatId, (int) $res['message_id'], $delay]
            );
        }
    }

    // =====================================================================
    // Per-user DM language / open-dialog flag
    // =====================================================================

    /**
     * Record that the user has an OPEN direct chat with the bot. Called on every inbound private
     * message (a user can only message the bot if the dialog is open). We DM a user only when this
     * flag is set, so we never poke a never-opened dialog.
     */
    public static function markDm(int $userId): void
    {
        if ($userId === 0) {
            return;
        }
        DB::run(
            "INSERT INTO " . DB::table('users') . " (user_id, lang, has_dm, created_at, updated_at)
             VALUES (?, '', 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE has_dm = 1",
            [$userId]
        );
    }

    /** Whether the user has an open direct chat with the bot (see markDm). */
    public static function hasDm(int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }
        return (int) DB::fetchColumn("SELECT has_dm FROM " . DB::table('users') . " WHERE user_id = ?", [$userId]) === 1;
    }

    /** The user's preferred DM language code, or '' if not chosen yet. */
    public static function getUserLang(int $userId): string
    {
        $v = DB::fetchColumn("SELECT lang FROM " . DB::table('users') . " WHERE user_id = ?", [$userId]);
        return is_string($v) ? $v : '';
    }

    /**
     * The user's language plus when it was last changed (UNIX ts, 0 if no row). Used to
     * reconcile the panel cookie with the stored value at login ("newest change wins").
     *
     * @return array{lang:string, ts:int}
     */
    public static function getUserLangMeta(int $userId): array
    {
        $row = DB::fetch(
            "SELECT lang, UNIX_TIMESTAMP(updated_at) AS ts FROM " . DB::table('users') . " WHERE user_id = ?",
            [$userId]
        );
        return ['lang' => (string) ($row['lang'] ?? ''), 'ts' => (int) ($row['ts'] ?? 0)];
    }

    /** Send the DM help text (bot capabilities + reply-to-notification commands). */
    public static function sendHelp(int $userId): void
    {
        if ($userId === 0) {
            return;
        }
        $botUser = (string) Config::value('bot', 'BOT_USERNAME', '');
        Bot::call('sendMessage', [
            'chat_id' => $userId,
            'text'    => Lang::get('dm_help', self::userLangOrDefault($userId), ['bot' => $botUser]),
        ]);
    }

    /** Send the privacy notice in a DM (full text + a link to the web version). */
    public static function sendPrivacy(int $userId): void
    {
        if ($userId === 0) {
            return;
        }
        $lang   = self::userLangOrDefault($userId);
        $appUrl = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
        $text   = Lang::get('privacy_title', $lang) . "\n\n" . Lang::get('privacy_body', $lang);
        if ($appUrl !== '') {
            $text .= "\n\n" . Lang::get('privacy_web_hint', $lang, ['url' => $appUrl . '/admin/privacy']);
        }
        Bot::call('sendMessage', ['chat_id' => $userId, 'text' => $text, 'disable_web_page_preview' => true]);
    }

    /** Store the user's preferred DM language (ignored if the code isn't one of ours). */
    public static function setUserLang(int $userId, string $lang): void
    {
        if ($userId === 0 || !array_key_exists($lang, Lang::available())) {
            return;
        }
        DB::run(
            "INSERT INTO " . DB::table('users') . " (user_id, lang, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE lang = VALUES(lang), updated_at = NOW()",
            [$userId, $lang]
        );
        Logger::info('GroupManager: user language set', ['user' => $userId, 'lang' => $lang]);
    }

    /** The user's DM language, or the default (first available) if none chosen. */
    private static function userLangOrDefault(int $userId): string
    {
        $lang = self::getUserLang($userId);
        return $lang !== '' ? $lang : (string) (array_key_first(Lang::available()) ?? 'ru');
    }

    /**
     * DM the user an inline keyboard to pick their bot language. $greetAfter controls whether
     * the follow-up group dialog includes the "hi, I'm Ostrakon" greeting — true for the /start
     * onboarding, false for a plain /language switch.
     */
    public static function promptLanguage(int $userId, bool $greetAfter = true): void
    {
        if ($userId === 0) {
            return;
        }
        $g = $greetAfter ? '1' : '0';
        $rows = [];
        foreach (Lang::available() as $code => $langName) {
            $rows[] = [['text' => $langName, 'callback_data' => 'lang:' . $code . ':' . $g]];
        }
        Bot::call('sendMessage', [
            'chat_id'      => $userId,
            'text'         => Lang::get('choose_language', self::userLangOrDefault($userId)),
            'reply_markup' => ['inline_keyboard' => $rows],
        ]);
    }

    /**
     * Handle a 'lang:<code>:<greet>' inline-button tap: store the choice, confirm in the new
     * language, then show the group dialog (with the greeting only if <greet> = 1).
     *
     * @param array<string, mixed> $cb the callback_query object
     */
    public static function setLanguageFromCallback(array $cb): void
    {
        $userId = (int) ($cb['from']['id'] ?? 0);
        $parts  = explode(':', (string) ($cb['data'] ?? '')); // lang:<code>:<greet>
        $code   = $parts[1] ?? '';
        $greet  = ($parts[2] ?? '1') === '1';
        if (isset($cb['id'])) {
            Bot::call('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        }
        if ($userId === 0 || !array_key_exists($code, Lang::available())) {
            return;
        }
        self::setUserLang($userId, $code);

        $msgId = (int) ($cb['message']['message_id'] ?? 0);
        if ($msgId !== 0) {
            Bot::call('editMessageText', [
                'chat_id'    => $userId,
                'message_id' => $msgId,
                'text'       => Lang::get('language_set', $code),
            ]);
        }
        self::sendDialog($cb['from'] ?? ['id' => $userId], $greet);
    }

    // =====================================================================
    // Personal notification preferences (per admin, per group)
    // =====================================================================

    /**
     * The user's personal notification flags for a group.
     *
     * @return array{votes:bool, bans:bool, elders:bool}
     */
    public static function getNotifyPrefs(int $chatId, int $userId): array
    {
        $row = DB::fetch(
            "SELECT notify_votes, notify_bans, notify_elders FROM " . DB::table('participants')
            . " WHERE chat_id = ? AND user_id = ?",
            [$chatId, $userId]
        );
        return [
            'votes'  => (bool) (int) ($row['notify_votes'] ?? 0),
            'bans'   => (bool) (int) ($row['notify_bans'] ?? 0),
            'elders' => (bool) (int) ($row['notify_elders'] ?? 0),
        ];
    }

    /** Set the user's notification flags for a group (creates the participant row if needed). */
    public static function setNotifyPrefs(int $chatId, int $userId, bool $votes, bool $bans, bool $elders): void
    {
        self::ensureParticipant($chatId, $userId);
        DB::run(
            "UPDATE " . DB::table('participants')
            . " SET notify_votes = ?, notify_bans = ?, notify_elders = ? WHERE chat_id = ? AND user_id = ?",
            [$votes ? 1 : 0, $bans ? 1 : 0, $elders ? 1 : 0, $chatId, $userId]
        );
    }

    // =====================================================================
    // Onboarding (/start in DM, waiting for the bot to be added)
    // =====================================================================

    /**
     * /start in a DM: greet + show the user's groups. If we don't know their language yet, ask for
     * it first (the dialog follows the language pick); otherwise show the group-list dialog right
     * away. No marker is set — a bot-add is confirmed by DMing the live owner (see onBotAdded).
     *
     * @param array<string, mixed> $user the from object
     */
    public static function startDialog(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        // No language on file → ask for it; the dialog is sent after the user picks (callback).
        if (self::getUserLang($userId) === '') {
            self::promptLanguage($userId);
            Logger::info('GroupManager: /start — asking language', ['user' => $userId]);
            return;
        }
        self::sendDialog($user);
        Logger::info('GroupManager: /start', ['user' => $userId]);
    }

    /**
     * /groups in a DM: just re-show the group list (no greeting). Asks for a language first if none
     * is on file.
     *
     * @param array<string, mixed> $user the from object
     */
    public static function showGroups(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        if (self::getUserLang($userId) === '') {
            self::promptLanguage($userId);
            return;
        }
        self::sendDialog($user, false);
        Logger::info('GroupManager: /groups', ['user' => $userId]);
    }

    /**
     * DM the user the list of groups where they're the OWNER and the bot is connected, plus
     * an "add me to a new group" button. Only owners drive the bot from the DM; other admins
     * reach the panel via a login link the owner shares (they can't DM the bot on their own —
     * a bot can't start a conversation).
     *
     * @param array<string, mixed> $user
     */
    private static function sendDialog(array $user, bool $greeting = true): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        $lang    = self::userLangOrDefault($userId); // DM in the user's chosen language
        $appUrl  = rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
        $botUser = (string) Config::value('bot', 'BOT_USERNAME', '');

        // Groups where this user is the OWNER. Mark those where the bot isn't yet an admin with
        // the ban right (⚠️) so the owner knows to promote it.
        $buttons   = [];
        $needAdmin = false;
        $groups = DB::fetchAll("SELECT chat_id, title FROM " . DB::table('groups') . " WHERE is_active = 1");
        foreach ($groups as $g) {
            $cid = (int) $g['chat_id'];
            if (!self::isOwner($cid, $userId)) {
                continue;
            }
            $canEnforce = self::botCanEnforce($cid);
            $needAdmin  = $needAdmin || !$canEnforce;
            $buttons[] = [[
                'text' => ($canEnforce ? '⚙️ ' : '⚠️ ') . (string) ($g['title'] ?? $cid),
                'url'  => $appUrl . '/admin/group/' . $cid,
            ]];
        }

        $lines = [];
        if ($greeting) {
            $lines[] = Lang::get('start_greeting', $lang);
        }
        $lines[] = Lang::get($buttons !== [] ? 'start_your_groups' : 'start_no_groups', $lang);
        if ($needAdmin) {
            $lines[] = Lang::get('start_need_admin', $lang);
        }

        // Button to add the bot to a new group (native Telegram flow).
        $buttons[] = [[
            'text' => Lang::get('start_add_group', $lang),
            'url'  => 'https://t.me/' . $botUser . '?startgroup=true',
        ]];

        Bot::call('sendMessage', [
            'chat_id'      => $userId,
            'text'         => implode("\n\n", $lines),
            'reply_markup' => ['inline_keyboard' => $buttons],
        ]);
    }

    /**
     * Cron: drive the onboarding of groups with a pending state. Phases:
     *  - onboarding_pending = 1 → a deferred supergroup add awaiting its FIRST onboarding pass;
     *  - onboarding_adder set   → a deferred owner-check (leave a confirmed non-owner);
     *  - otherwise              → waiting for the ban right (post/remove the in-group hint).
     */
    public static function runOnboardingChecks(): void
    {
        $rows = DB::fetchAll(
            "SELECT chat_id, title, lang, onboarding_pending, onboarding_adder, onboarding_hint_msg_id,
                    TIMESTAMPDIFF(SECOND, onboarding_at, NOW()) AS age
               FROM " . DB::table('groups') . "
              WHERE onboarding_at IS NOT NULL AND is_active = 1"
        );
        foreach ($rows as $g) {
            $chatId = (int) $g['chat_id'];
            $age    = (int) $g['age'];
            if ((int) ($g['onboarding_pending'] ?? 0) === 1) {
                // Let a possible migration land first (migrateChat wipes this row) before we act.
                if ($age >= 15) {
                    self::onboardingInitialPass($chatId);
                }
            } elseif ($g['onboarding_adder'] !== null) {
                self::onboardingCheckLeave($chatId, (int) $g['onboarding_adder'], $age);
            } else {
                self::onboardingCheckRights($chatId, $g, $age);
            }
        }
    }

    /**
     * Deferred FIRST onboarding pass for a supergroup add (onboarding_pending was 1). If a migration
     * happened after the add, migrateChat already wiped this row, so we never get here for it — no
     * duplicate. Clears the pending flag, then runs the same first pass a basic-group add runs
     * synchronously.
     */
    private static function onboardingInitialPass(int $chatId): void
    {
        $group = self::getGroup($chatId);
        if ($group === null) {
            return;
        }
        $adderId = (int) ($group['onboarding_adder'] ?? 0);
        // Consume the pending flag (and the temporary adder we stashed): whatever this pass turns
        // into (onboardStart / deferred leave), the group is no longer "awaiting its first pass".
        DB::run(
            "UPDATE " . DB::table('groups') . " SET onboarding_pending = 0, onboarding_adder = NULL, onboarding_at = NULL WHERE chat_id = ?",
            [$chatId]
        );
        self::onboardFirstPass($chatId, $group, $adderId);
    }

    /**
     * Deferred owner-check for a suspected non-owner add. We wait 10 min (so any in-flight migration
     * settles — migrateChat would overwrite the row and cancel this), then leave only on a CONFIRMED
     * non-owner; an undetermined lookup keeps waiting.
     */
    private static function onboardingCheckLeave(int $chatId, int $adderId, int $ageSeconds): void
    {
        if ($ageSeconds < 600) {
            return;
        }
        $m = self::getMember($chatId, $adderId);
        if ($m === null) {
            return; // still can't determine → retry next tick
        }
        // Remove the "grant me the ban right" hint (if we posted one) — its moment has passed.
        $group  = self::getGroup($chatId);
        $hintId = (int) (($group['onboarding_hint_msg_id'] ?? 0));
        if ($hintId > 0) {
            Bot::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $hintId], true);
        }

        if (($m['status'] ?? '') === 'creator') {
            // The adder was the owner after all (an earlier lookup glitched) → onboard normally.
            self::onboardingClear($chatId);
            if ($group !== null) {
                self::onboardStart($chatId, $group);
            }
            return;
        }
        // Confirmed non-owner → refuse and leave. Keep the data if it's the owner's existing group
        // (a non-owner re-added it); a brand-new empty row is dropped.
        Bot::call('sendMessage', ['chat_id' => $chatId, 'text' => Lang::get('group_owner_only', (string) ($group['lang'] ?? 'ru'))]);
        Bot::call('leaveChat', ['chat_id' => $chatId]);
        // Telemetry: the bot left on its own ("added, nobody figured it out"). Recorded here so the
        // resulting left→onBotRemoved event can tell this apart from a human kick (see onBotRemoved).
        Telemetry::record('bot_left', $chatId);
        self::onboardingClear($chatId);
        self::setActive($chatId, false);
        self::dropIfEmpty($chatId);
        Logger::info('GroupManager: non-owner add confirmed — left', ['chat_id' => $chatId, 'by' => $adderId]);
    }

    /**
     * "Waiting for the ban right" phase: +1 min → post the in-group hint (only if no open dialog);
     * +10 min → give up (remove the hint / tell the owner). Rights obtained is normally caught by
     * my_chat_member; this also reconciles it as a fallback.
     *
     * @param array<string, mixed> $g a groups row (chat_id, title, lang, onboarding_hint_msg_id)
     */
    private static function onboardingCheckRights(int $chatId, array $g, int $ageSeconds): void
    {
        if (self::botHasBanRight($chatId)) {
            $group = self::getGroup($chatId);
            if ($group !== null) {
                self::onboardingRightsObtained($chatId, $group);
            }
            return;
        }

        $ownerId    = self::ownerId($chatId);
        $dialogOpen = $ownerId !== 0 && self::hasDm($ownerId);
        $hintId     = (int) ($g['onboarding_hint_msg_id'] ?? 0);
        $title      = (string) ($g['title'] ?? $chatId);

        if ($ageSeconds >= 600) {
            if ($hintId > 0) {
                Bot::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $hintId], true);
            }
            if ($dialogOpen) {
                Bot::call('sendMessage', [
                    'chat_id' => $ownerId,
                    'text'    => Lang::get('group_rights_missing', self::userLangOrDefault($ownerId), ['title' => $title]),
                ]);
            }
            self::onboardingClear($chatId);
            return;
        }

        // Post the in-group hint only when the owner is KNOWN but has no open dialog. If the owner
        // can't be determined (ownerId == 0 — a transient / upgrade still settling) we skip and
        // retry next tick, so we never flicker a message into a group we can't yet read.
        if ($ageSeconds >= 60 && $hintId === 0 && $ownerId !== 0 && !$dialogOpen) {
            $botUser = (string) Config::value('bot', 'BOT_USERNAME', '');
            $res = Bot::call('sendMessage', [
                'chat_id' => $chatId,
                'text'    => Lang::get('group_setup_hint', (string) ($g['lang'] ?? 'ru'), ['bot' => $botUser]),
            ]);
            if (is_array($res) && isset($res['message_id'])) {
                DB::run(
                    "UPDATE " . DB::table('groups') . " SET onboarding_hint_msg_id = ? WHERE chat_id = ?",
                    [(int) $res['message_id'], $chatId]
                );
            }
        }
    }

    /** Delete a group row that holds no data of its own (used after rejecting a fresh non-owner add). */
    private static function dropIfEmpty(int $chatId): void
    {
        $has = (int) DB::fetchColumn(
            "SELECT (SELECT COUNT(*) FROM " . DB::table('participants') . " WHERE chat_id = ?)
                  + (SELECT COUNT(*) FROM " . DB::table('messages') . " WHERE chat_id = ?)
                  + (SELECT COUNT(*) FROM " . DB::table('votes') . " WHERE chat_id = ?)",
            [$chatId, $chatId, $chatId]
        );
        if ($has === 0) {
            DB::run("DELETE FROM " . DB::table('groups') . " WHERE chat_id = ?", [$chatId]);
        }
    }
}
