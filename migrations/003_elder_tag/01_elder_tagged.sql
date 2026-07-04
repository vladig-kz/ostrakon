-- Ostrakon — migration 003: track whether the elder tag is currently applied.
--
-- elder_tagged lets score_recalc call setChatMemberTag only on transitions
-- (became / stopped being an elder), not on every recalculation.
-- {prefix} is replaced by the migration runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}participants
    ADD COLUMN elder_tagged TINYINT NOT NULL DEFAULT 0;
