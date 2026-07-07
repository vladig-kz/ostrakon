-- Ostrakon — migration 011: the pending_setup table is no longer used (its role is now covered by
-- the per-group onboarding columns + the has_dm flag). {prefix} is replaced by the runner.

DROP TABLE IF EXISTS {prefix}pending_setup;
