-- Ostrakon — migration 011: swap the old pending_setup cron task for the new onboarding check.
-- {prefix} is replaced by the runner with DB_TABLE_PREFIX.

DELETE FROM {prefix}cron_schedule WHERE task = 'pending_setup_ttl';

INSERT IGNORE INTO {prefix}cron_schedule (task, next_run_at) VALUES ('onboarding_check', NOW());
