-- Ostrakon — migration 009: mark whether the bot is currently connected to a group.
--
-- When the bot is removed we KEEP the group's data (settings/history, for a re-add or export),
-- but flag it inactive so we stop making live getChatMember calls for it (which would fail) and
-- stop listing it in the owner's dialog. Re-adding the bot flips it back to 1.
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}groups
    ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1;
