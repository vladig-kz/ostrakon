-- Ostrakon — migration 006: personal notification preferences (per admin, per group).
--
-- Each admin opts IN for themselves (default off); the owner can't set these for others.
-- Flags live on the participant row (per chat_id + user_id). Delivery is a DM, so it only
-- reaches admins who have an open chat with the bot.
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}participants
    ADD COLUMN notify_votes  TINYINT NOT NULL DEFAULT 0,   -- DM on a vote start (who vs whom)
    ADD COLUMN notify_bans   TINYINT NOT NULL DEFAULT 0,   -- DM on a vote outcome (ban / declined / expired / cancelled)
    ADD COLUMN notify_elders TINYINT NOT NULL DEFAULT 0;   -- DM when a member becomes / stops being an elder
