-- Ostrakon — migration 002: queue of incoming Telegram updates.
-- webhook.php only pushes an update here and returns 200 (fast, no Telegram calls).
-- The worker (cron.php) drains the queue and runs the Handler.
-- update_id UNIQUE — deduplicates Telegram retries (guards against double processing).

CREATE TABLE IF NOT EXISTS {prefix}update_queue (
    id         BIGINT AUTO_INCREMENT,
    update_id  BIGINT NOT NULL,                 -- Telegram update_id (for deduplication)
    payload    MEDIUMTEXT NOT NULL,             -- raw update JSON
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_update_id (update_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
