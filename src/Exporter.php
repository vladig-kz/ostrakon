<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Exporter — JSON export/import of ONE group's data for migrating it to another
 * instance of the bot (self-hosting, or moving from a public instance).
 *
 * Key idea: the chat_id is NOT remapped — it's the SAME Telegram group, just served by a
 * new bot. History (finished votes, elder scores, message stats, participant state) is
 * imported ADDITIVELY next to whatever the new bot has already accumulated; sorting by date
 * puts the old records in their natural place.
 *
 * What is NOT exported: the bot token (a new instance = a new bot), active votes (they keep
 * running wherever they are — export must not disturb them), and operational tables
 * (cron_schedule, pending_setup, bot_messages).
 *
 * Import is idempotent (safe to run twice):
 *   - groups/participants — UPSERT by their natural key (chat_id / chat_id+user_id);
 *   - messages            — inserted only if no row with the same (chat_id, user_id, sent_at);
 *   - votes               — inserted only if no finished vote with the same
 *                           (chat_id, target_id, initiator_id, started_at); its vote_records
 *                           and suspects follow via an old→new vote id map.
 */
final class Exporter
{
    /** Marker in the file so we don't try to import an arbitrary JSON. */
    public const FORMAT = 'ostrakon-export';

    /** Export format version. Import requires an EXACT match (no auto format migration). */
    public const SCHEMA_VERSION = 1;

    /** Whitelisted, id-free column lists (import never trusts the file's surrogate ids). */
    private const PARTICIPANT_COLS = [
        'chat_id', 'user_id', 'username', 'joined_at', 'score', 'score_at',
        'msg_count', 'is_protected', 'banned_at', 'reentry_until', 'can_manage',
        // Intentionally NOT transferred: is_elder (derived — recomputed from the imported
        // messages at the next score_recalc) and notify_* (personal opt-ins tied to a DM with
        // THIS bot; on a new instance each admin re-subscribes with the new bot).
    ];
    private const MESSAGE_COLS = ['chat_id', 'user_id', 'sent_at', 'reply_to_msg_id'];
    private const VOTE_COLS = [
        'chat_id', 'target_id', 'initiator_id', 'trigger_msg_id', 'trigger_text',
        'trigger_date', 'vote_message_id', 'started_at', 'readonly_at', 'finished_at',
        'status', 'used_threshold',
    ];
    private const VOTE_RECORD_COLS = ['vote_id', 'voter_id', 'direction', 'weight', 'voted_at'];
    private const SUSPECT_COLS = [
        'chat_id', 'user_id', 'vote_id', 'is_elder_conflict', 'cleared', 'cleared_at',
    ];

    // =====================================================================
    // Export
    // =====================================================================

    /**
     * Collect all portable data of a group into an associative array (ready for json_encode).
     *
     * @return array<string, mixed>
     */
    public static function export(int $chatId): array
    {
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            throw new RuntimeException('group not found: ' . $chatId);
        }

        // Finished votes only — active ones keep running and must not be moved.
        $votes = DB::fetchAll(
            "SELECT * FROM " . DB::table('votes') . " WHERE chat_id = ? AND status <> 'active'",
            [$chatId]
        );
        $voteIds = array_map(static fn(array $v): int => (int) $v['id'], $votes);

        $voteRecords = [];
        $suspects    = [];
        if ($voteIds !== []) {
            $in = implode(',', array_fill(0, count($voteIds), '?'));
            $voteRecords = DB::fetchAll(
                "SELECT * FROM " . DB::table('vote_records') . " WHERE vote_id IN ({$in})",
                $voteIds
            );
            $suspects = DB::fetchAll(
                "SELECT * FROM " . DB::table('suspects') . " WHERE chat_id = ? AND vote_id IN ({$in})",
                array_merge([$chatId], $voteIds)
            );
        }

        return [
            'format'         => self::FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => gmdate('Y-m-d\TH:i:s\Z'),
            'chat_id'        => $chatId,
            'data'           => [
                'group'        => $group,
                'participants' => DB::fetchAll(
                    "SELECT * FROM " . DB::table('participants') . " WHERE chat_id = ?",
                    [$chatId]
                ),
                'messages'     => DB::fetchAll(
                    "SELECT * FROM " . DB::table('messages') . " WHERE chat_id = ?",
                    [$chatId]
                ),
                'votes'        => $votes,
                'vote_records' => $voteRecords,
                'suspects'     => $suspects,
            ],
        ];
    }

    // =====================================================================
    // Import
    // =====================================================================

    /**
     * Import a previously exported file INTO the group with the same chat_id.
     * Runs in a single transaction; on any error everything rolls back.
     *
     * @param array<string, mixed> $json decoded export file
     * @return array<string, int>        per-table counters for the success message
     */
    public static function import(int $chatId, array $json): array
    {
        self::validate($chatId, $json);
        $data = (array) ($json['data'] ?? []);

        $counts = [
            'participants'  => 0,
            'messages'      => 0,
            'votes'         => 0,
            'votes_skipped' => 0,
            'vote_records'  => 0,
            'suspects'      => 0,
        ];

        DB::begin();
        try {
            // Make sure the group row exists, then restore ALL settings from the file.
            GroupManager::ensureGroup($chatId);
            self::importGroup($chatId, (array) ($data['group'] ?? []));

            foreach ((array) ($data['participants'] ?? []) as $row) {
                self::importParticipant($chatId, (array) $row);
                $counts['participants']++;
            }

            foreach ((array) ($data['messages'] ?? []) as $row) {
                if (self::importMessage($chatId, (array) $row)) {
                    $counts['messages']++;
                }
            }

            // votes → old id → new id map (children attach only to freshly inserted votes)
            $voteMap = [];
            foreach ((array) ($data['votes'] ?? []) as $row) {
                $row    = (array) $row;
                $oldId  = (int) ($row['id'] ?? 0);
                $newId  = self::importVote($chatId, $row);
                if ($newId === null) {
                    $counts['votes_skipped']++;
                    continue;
                }
                $voteMap[$oldId] = $newId;
                $counts['votes']++;
            }

            foreach ((array) ($data['vote_records'] ?? []) as $row) {
                $row   = (array) $row;
                $newId = $voteMap[(int) ($row['vote_id'] ?? 0)] ?? null;
                if ($newId !== null && self::importVoteRecord($newId, $row)) {
                    $counts['vote_records']++;
                }
            }

            foreach ((array) ($data['suspects'] ?? []) as $row) {
                $row   = (array) $row;
                $newId = $voteMap[(int) ($row['vote_id'] ?? 0)] ?? null;
                if ($newId !== null) {
                    self::importSuspect($chatId, $newId, $row);
                    $counts['suspects']++;
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error('Exporter: import failed', $e, ['chat_id' => $chatId]);
            throw $e;
        }

        // Recompute score + elder status/tags now (full mode) so imported elders show right
        // away, not only after the next daily score_recalc. Done after commit (it calls
        // Telegram for tags); silent — no elder notifications for the backfilled history.
        ScoreManager::refreshGroupNow($chatId);

        Logger::info('Exporter: import done', array_merge(['chat_id' => $chatId], $counts));
        return $counts;
    }

    /**
     * Check the file is one of ours, the right version and for THIS group.
     *
     * @param array<string, mixed> $json
     */
    private static function validate(int $chatId, array $json): void
    {
        if (($json['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('import_bad_file');
        }
        if ((int) ($json['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('import_bad_version');
        }
        if ((int) ($json['chat_id'] ?? 0) !== $chatId) {
            throw new RuntimeException('import_wrong_group');
        }
    }

    /** groups: UPSERT every column from the file (settings restore); chat_id is the key. */
    private static function importGroup(int $chatId, array $row): void
    {
        if ($row === []) {
            return;
        }
        // is_active reflects THIS instance's live connection (the bot was just added here), not
        // the source instance — never let the file override it.
        unset($row['is_active']);
        $row['chat_id'] = $chatId;
        $cols = array_keys($row);
        $set  = [];
        foreach ($cols as $c) {
            if ($c !== 'chat_id') {
                $set[] = "{$c} = VALUES({$c})";
            }
        }
        $t            = DB::table('groups');
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        DB::run(
            "INSERT INTO {$t} (" . implode(', ', $cols) . ") VALUES ({$placeholders})
             ON DUPLICATE KEY UPDATE " . implode(', ', $set),
            array_values($row)
        );
    }

    /** participants: UPSERT by (chat_id, user_id); the import wins on all historical fields. */
    private static function importParticipant(int $chatId, array $row): void
    {
        $vals = self::pick($row, self::PARTICIPANT_COLS, $chatId);
        $t    = DB::table('participants');
        $set  = [];
        foreach (self::PARTICIPANT_COLS as $c) {
            if ($c !== 'chat_id' && $c !== 'user_id') {
                $set[] = "{$c} = VALUES({$c})";
            }
        }
        $placeholders = implode(', ', array_fill(0, count(self::PARTICIPANT_COLS), '?'));
        DB::run(
            "INSERT INTO {$t} (" . implode(', ', self::PARTICIPANT_COLS) . ") VALUES ({$placeholders})
             ON DUPLICATE KEY UPDATE " . implode(', ', $set),
            $vals
        );
    }

    /**
     * messages: insert only if no row with the same (chat_id, user_id, sent_at). This key is
     * EXACT, not approximate: the anti-flood filter (msg_cooldown_minutes) means one user's
     * counted messages are already minutes apart, so two stored rows can't share a sent_at.
     */
    private static function importMessage(int $chatId, array $row): bool
    {
        $vals = self::pick($row, self::MESSAGE_COLS, $chatId);
        $t    = DB::table('messages');
        // INSERT ... SELECT ... WHERE NOT EXISTS keeps the check+insert atomic (one statement).
        $placeholders = implode(', ', array_fill(0, count(self::MESSAGE_COLS), '?'));
        $stmt = DB::run(
            "INSERT INTO {$t} (" . implode(', ', self::MESSAGE_COLS) . ")
             SELECT {$placeholders} FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM {$t} WHERE chat_id = ? AND user_id = ? AND sent_at = ?)",
            array_merge($vals, [$chatId, $row['user_id'] ?? null, $row['sent_at'] ?? null])
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * votes: dedup by (chat_id, target_id, initiator_id, started_at). Returns the new id, or
     * null if such a finished vote already exists (then its children are skipped too).
     */
    private static function importVote(int $chatId, array $row): ?int
    {
        $t       = DB::table('votes');
        $exists  = DB::fetchColumn(
            "SELECT id FROM {$t} WHERE chat_id = ? AND target_id = ? AND initiator_id = ? AND started_at = ?",
            [$chatId, $row['target_id'] ?? null, $row['initiator_id'] ?? null, $row['started_at'] ?? null]
        );
        if ($exists !== null) {
            return null;
        }
        $vals         = self::pick($row, self::VOTE_COLS, $chatId);
        $placeholders = implode(', ', array_fill(0, count(self::VOTE_COLS), '?'));
        DB::run(
            "INSERT INTO {$t} (" . implode(', ', self::VOTE_COLS) . ") VALUES ({$placeholders})",
            $vals
        );
        return (int) DB::lastInsertId();
    }

    /** vote_records: insert under the new vote id (UNIQUE vote_id+voter_id makes it idempotent). */
    private static function importVoteRecord(int $newVoteId, array $row): bool
    {
        $row['vote_id'] = $newVoteId;
        $vals           = self::pick($row, self::VOTE_RECORD_COLS, null);
        $t              = DB::table('vote_records');
        $placeholders   = implode(', ', array_fill(0, count(self::VOTE_RECORD_COLS), '?'));
        $stmt = DB::run(
            "INSERT IGNORE INTO {$t} (" . implode(', ', self::VOTE_RECORD_COLS) . ") VALUES ({$placeholders})",
            $vals
        );
        return $stmt->rowCount() > 0;
    }

    /** suspects: insert under the new vote id (only reached for freshly inserted votes). */
    private static function importSuspect(int $chatId, int $newVoteId, array $row): void
    {
        $row['vote_id'] = $newVoteId;
        $vals           = self::pick($row, self::SUSPECT_COLS, $chatId);
        $t              = DB::table('suspects');
        $placeholders   = implode(', ', array_fill(0, count(self::SUSPECT_COLS), '?'));
        DB::run(
            "INSERT INTO {$t} (" . implode(', ', self::SUSPECT_COLS) . ") VALUES ({$placeholders})",
            $vals
        );
    }

    /**
     * Build an ordered value list for the given column whitelist. chat_id (when the table has
     * it) is forced to the target group; any missing column becomes NULL.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $cols
     * @return list<mixed>
     */
    private static function pick(array $row, array $cols, ?int $chatId): array
    {
        $vals = [];
        foreach ($cols as $c) {
            if ($c === 'chat_id' && $chatId !== null) {
                $vals[] = $chatId;
            } else {
                $vals[] = $row[$c] ?? null;
            }
        }
        return $vals;
    }
}
