-- Ostrakon — migration 010: remember which forum topic (message thread) a vote belongs to.
--
-- Forum supergroups split a chat into topics; a message there carries a message_thread_id. We
-- store the trigger message's thread on the vote so the vote message AND its (possibly deferred,
-- cron-posted) result land in the SAME topic, not in "General". NULL = ordinary group / General.
-- {prefix} is replaced by the migration runner with DB_TABLE_PREFIX.

ALTER TABLE {prefix}votes
    ADD COLUMN thread_id BIGINT NULL;
