-- Ostrakon — migration 012: defer a supergroup add's FIRST onboarding pass to the cron.
--
-- A bot added to a SUPERGROUP might be a genuine add OR a basic→supergroup upgrade still settling
-- (migrateChat hasn't merged the row yet). We can't tell at add time, so we set onboarding_pending=1
-- and let the cron run the first onboarding pass on its next tick: by then a migration (if any) has
-- merged and wiped this row, so an upgrade yields no duplicate onboarding. Adds to a BASIC group are
-- never a migration in progress → onboarded immediately (pending stays 0). {prefix} — the runner.

ALTER TABLE {prefix}groups
    ADD COLUMN onboarding_pending TINYINT NOT NULL DEFAULT 0;
