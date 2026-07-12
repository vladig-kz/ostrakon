<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Telemetry — aggregate operator metrics (no personal data), shown on the superadmin page.
 *
 * A tiny append-only event log: one row per counted event. The timestamp is the DB's own NOW()
 * (the single source of time), so a window like "last week"/"last month" is just a created_at
 * range. Recording is fire-and-forget — it must NEVER break the main flow, so every write is
 * wrapped in try/catch and failures are only logged.
 *
 * Event codes:
 *   bot_added            — the bot was added to a group
 *   bot_admin_granted    — the bot obtained the ban right (added as admin or promoted)
 *   bot_removed          — the bot was kicked/removed by a human
 *   bot_left             — the bot left on its own (a non-owner added it → refused)
 *   vote_created         — a vote was opened
 *   vote_banned_vote     — banned: the community reached the "for" threshold
 *   vote_declined_vote   — declined: the community reached the "against" threshold
 *   vote_banned_admin    — banned by an admin (button / DM command / instant-ban init)
 *   vote_declined_admin  — declined by an admin (button)
 *   vote_cancelled_admin — cancelled by an admin (cancelban / protect)
 *   vote_banned_manual   — the admin banned the target directly in Telegram
 *   vote_expired         — closed by timeout (T1/T2)
 *   vote_cancelled       — cancelled otherwise (vote message deleted / data erasure)
 */
final class Telemetry
{
    /** Record one telemetry event (fire-and-forget). $chatId is optional context (0 = n/a). */
    public static function record(string $event, int $chatId = 0): void
    {
        try {
            DB::run(
                "INSERT INTO " . DB::table('telemetry') . " (event, chat_id, created_at) VALUES (?, ?, NOW())",
                [$event, $chatId]
            );
        } catch (Throwable $e) {
            Logger::warning('Telemetry: record failed', $e, ['event' => $event, 'chat_id' => $chatId]);
        }
    }

    /**
     * Whether $event was recorded for $chatId within the last $seconds (DB clock). Used to tell the
     * bot's own self-leave from a human kick: both surface as a "left" my_chat_member, but the
     * self-leave path records bot_left just before it, so onBotRemoved can skip the duplicate.
     */
    public static function recordedRecently(string $event, int $chatId, int $seconds): bool
    {
        try {
            return (bool) DB::fetchColumn(
                "SELECT 1 FROM " . DB::table('telemetry') . "
                  WHERE event = ? AND chat_id = ? AND created_at > NOW() - INTERVAL ? SECOND
                  LIMIT 1",
                [$event, $chatId, $seconds]
            );
        } catch (Throwable $e) {
            Logger::warning('Telemetry: recordedRecently failed', $e, ['event' => $event, 'chat_id' => $chatId]);
            return false;
        }
    }

    /**
     * Counts per event over three windows for the operator panel, in a single table scan:
     * last 7 days, last 30 days, all time. Returned keyed by event; events with no rows are simply
     * absent (the view fills missing ones with zero from its own ordered label list).
     *
     * @return array<string, array{d7:int, d30:int, all:int}>
     */
    public static function summary(): array
    {
        $rows = DB::fetchAll(
            "SELECT event,
                    SUM(created_at > NOW() - INTERVAL 7 DAY)  AS d7,
                    SUM(created_at > NOW() - INTERVAL 30 DAY) AS d30,
                    COUNT(*)                                  AS all_time
               FROM " . DB::table('telemetry') . "
              GROUP BY event"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['event']] = [
                'd7'  => (int) $r['d7'],
                'd30' => (int) $r['d30'],
                'all' => (int) $r['all_time'],
            ];
        }
        return $out;
    }

    /** Cron (data_ttl): drop telemetry rows older than $days. Returns the number deleted. */
    public static function purgeOld(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        return DB::run(
            "DELETE FROM " . DB::table('telemetry') . " WHERE created_at < NOW() - INTERVAL ? DAY",
            [$days]
        )->rowCount();
    }
}
