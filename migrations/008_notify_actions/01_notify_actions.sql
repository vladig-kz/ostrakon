-- Ostrakon — migration 008: context for reply-to-notification moderation commands.
--
-- When the bot DMs an admin a vote-start or ban notification, it records here what that
-- message refers to. If the admin replies to it with a command (forceban/cancelban/protect/
-- unban), the bot looks up the context by (user_id, message_id) and acts — without cluttering
-- the group with commands. kind = 'vote' (has vote_id) or 'ban'.
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

CREATE TABLE IF NOT EXISTS {prefix}notify_actions (
    user_id    BIGINT      NOT NULL,          -- the admin's DM (= their user_id)
    message_id BIGINT      NOT NULL,          -- the notification message_id in that DM
    chat_id    BIGINT      NOT NULL,          -- the group the event is about
    target_id  BIGINT      NOT NULL,          -- the user an action would target
    vote_id    BIGINT,                        -- the vote (for 'vote' kind); NULL for 'ban'
    kind       VARCHAR(16) NOT NULL,          -- 'vote' | 'ban' — which commands are allowed
    created_at DATETIME    NOT NULL,          -- for TTL cleanup (UTC)
    PRIMARY KEY (user_id, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
