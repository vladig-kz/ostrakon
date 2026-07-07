-- Ostrakon — migration 011: remember whether a user has an OPEN direct chat with the bot.
--
-- A bot can't message a user who never started it. We set has_dm=1 whenever the user sends the bot
-- a private message (only possible if the dialog is open), and DM them ONLY when has_dm=1 — so we
-- never poke a never-opened dialog (which Telegram could treat as spam). Not back-filled: it fills
-- forward as users interact. {prefix} is replaced by the runner.

ALTER TABLE {prefix}users
    ADD COLUMN has_dm TINYINT NOT NULL DEFAULT 0;
