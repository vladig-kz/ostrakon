-- Ostrakon — migration 007: persistent "is an elder" status, independent of the visible tag.
--
-- elder_tagged tracks the VISIBLE tag (only maintained when elder_title is set). is_elder
-- tracks the STATUS itself (score >= elder_threshold), so status-change notifications fire
-- even when tagging is disabled. Updated on every score recalc.
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}participants
    ADD COLUMN is_elder TINYINT NOT NULL DEFAULT 0;
