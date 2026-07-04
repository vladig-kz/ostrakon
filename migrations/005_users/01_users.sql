-- Ostrakon — migration 005: per-user preferences for bot DMs.
--
-- For now just the user's preferred language for direct messages (the onboarding dialog and
-- the personal notifications). user_id doubles as the user's private-chat id. The language is
-- set from a lang file's _language_code, either via the /start /language keyboard or from the
-- panel (when the admin ticks a notification box we know their panel language).
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

CREATE TABLE IF NOT EXISTS {prefix}users (
    user_id    BIGINT      NOT NULL,          -- Telegram user_id (= their private-chat id); PK
    lang       VARCHAR(16) NOT NULL,          -- preferred DM language code (a lang file's _language_code)
    created_at DATETIME    NOT NULL,          -- first seen (UTC)
    updated_at DATETIME    NOT NULL,          -- last language change (UTC)
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
