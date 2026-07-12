-- Ostrakon — migration 013: operator telemetry (aggregate usage metrics, no personal data).
--
-- A tiny append-only event log for the hoster's superadmin page: one row per counted event
-- (bot added / given the ban right / removed / self-left; a vote created / how it ended). The
-- DB's own NOW() is the timestamp — the single source of time — so any window ("last week",
-- "last month") is a plain created_at range. Groups don't matter for the stats, but chat_id is
-- kept for de-duplication (self-leave vs kicked) and debugging. {prefix} — the runner.

CREATE TABLE IF NOT EXISTS {prefix}telemetry (
    id         BIGINT AUTO_INCREMENT,                 -- surrogate PK
    event      VARCHAR(32) NOT NULL,                  -- canonical event code (bot_added, vote_expired, …)
    chat_id    BIGINT      NOT NULL DEFAULT 0,         -- which group (context only; 0 if n/a)
    created_at DATETIME    NOT NULL,                   -- when it happened (UTC, via NOW()) — basis for time windows
    PRIMARY KEY (id),
    KEY idx_event_time (event, created_at),            -- GROUP BY event over a created_at range
    KEY idx_chat_event_time (chat_id, event, created_at) -- self-leave/kick de-dup lookup
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
