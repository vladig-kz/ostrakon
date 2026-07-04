-- Ostrakon — migration 004: per-group "settings manager" grant.
--
-- can_manage = 1 means the group owner authorized this admin to change the bot's
-- settings (settings page + elder simulator). The owner always can; other admins
-- only with this flag. Granting/revoking is owner-only and admin-only (enforced in code).
-- {prefix} is replaced by the migration runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}participants
    ADD COLUMN can_manage TINYINT NOT NULL DEFAULT 0;
