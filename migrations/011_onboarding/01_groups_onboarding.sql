-- Ostrakon — migration 011: per-group onboarding state (replaces the pending_setup table).
--
-- When the bot is added / loses the ban right, we track a short "setup" state on the group itself:
--   onboarding_at          — when the current state started (drives the +1/+10 min cron checks);
--   onboarding_adder       — a NOT-NULL value means we are DEFERRING an owner-check on this adder
--                            (leave if confirmed non-owner); NULL means the state is "waiting for
--                            the ban right";
--   onboarding_hint_msg_id — id of the in-group "grant me the ban right" hint, so we can delete it.
-- All NULL = the group is not in any onboarding state. {prefix} is replaced by the runner.

ALTER TABLE {prefix}groups
    ADD COLUMN onboarding_at          DATETIME NULL,
    ADD COLUMN onboarding_adder       BIGINT   NULL,
    ADD COLUMN onboarding_hint_msg_id BIGINT   NULL;
