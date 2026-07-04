-- Ostrakon — migration 001_initial: base DB schema.
-- Compatibility: MySQL 5.7+/8.x and MariaDB 10.x. InnoDB engine, utf8mb4_unicode_ci.
--
-- {prefix} is replaced by the runner (migrations/run.php) with DB_TABLE_PREFIX.
-- IF NOT EXISTS — for idempotency (a repeated/partial run is safe).
--
-- Time: all DATETIMEs are in UTC; values are set via NOW() on the DB side. Group setting
-- defaults are NOT in the DDL (they come from config/defaults.php); operational DEFAULTs
-- (0, 'active') are kept.

-- =====================================================================
-- groups — settings and state of each group.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}groups (
    chat_id                  BIGINT       NOT NULL,            -- Telegram chat_id of the group (negative); also the PK
    title                    VARCHAR(255),                     -- group title (for display in the panel)
    mode                     ENUM('light','full') NOT NULL,    -- mode: light (voting only) / full (message accounting, score)
    elder_title              VARCHAR(64)  NOT NULL,             -- what the "elder" status is called in the bot texts
    min_age_hours            INT          NOT NULL,             -- min tenure in the group (hours) to vote/initiate
    min_messages             INT          NOT NULL,             -- min counted messages to be eligible to vote (full only)
    min_msg_length           INT          NOT NULL,             -- messages shorter than N chars aren't counted (full only)
    msg_cooldown_minutes     INT          NOT NULL,             -- anti-flood: interval between counted messages (full only)
    halflife_days            INT          NOT NULL,             -- half-life of a message's contribution to score (days)
    elder_threshold          DECIMAL(8,2) NOT NULL,             -- score ≥ this value → the member becomes an elder
    elder_weight             DECIMAL(4,2) NOT NULL,             -- an elder's vote weight (a regular member's is 1.0)
    ban_threshold            DECIMAL(8,2) NOT NULL,             -- sum of "for" weights sufficient for a ban
    readonly_ratio           DECIMAL(4,2) NOT NULL,             -- fraction of ban_threshold at which the target gets readonly
    ban_decline_threshold    DECIMAL(8,2) NOT NULL,             -- sum of "against" weights sufficient for a decline
    protected_ban_threshold  DECIMAL(8,2) NOT NULL,             -- raised ban threshold when the TARGET is an elder
    T1_hours                 INT          NOT NULL,             -- vote timeout with no "against" votes (hours)
    T2_hours                 INT          NOT NULL,             -- vote timeout with "against" votes present (T2 >> T1)
    cooldown_hours           INT          NOT NULL,             -- initiator protection from a counter-attack after a vote finishes
    reentry_ban_hours        INT          NOT NULL,             -- for how many hours after a ban a re-entry means auto-kick
    reentry_autokick         TINYINT      NOT NULL,             -- 1 = kick a re-entering user in the reentry window; 0 = don't interfere (admin is king)
    cleanup_delay_seconds    INT          NOT NULL,             -- after how long to delete service messages once a vote finishes
    reveal_delay_seconds     INT          NOT NULL,             -- delay before publishing the final list on a ban
    show_full_list           TINYINT      NOT NULL,             -- 1 = show the named list of voters in the ban result
    delete_trigger_message   TINYINT      NOT NULL,             -- 1 = delete the bot activation message (reply with @mention) immediately
    delete_spam_on_ban       TINYINT      NOT NULL,             -- 1 = delete the spam message (trigger) on a successful ban
    admin_instant_ban        TINYINT      NOT NULL,             -- admin initiation: 1 = ban instantly; 0 = put to a vote
    lang                     VARCHAR(16)  NOT NULL,             -- language code from a file's _language_code (ru, kk-KZ, …)
    added_at                 DATETIME     NOT NULL,             -- when the bot was added to the group (UTC)
    updated_at               DATETIME     NOT NULL,             -- when the settings were last changed (UTC)
    PRIMARY KEY (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- participants — group members, their score and status.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}participants (
    id            BIGINT AUTO_INCREMENT,                        -- surrogate PK
    chat_id       BIGINT       NOT NULL,                        -- which group
    user_id       BIGINT       NOT NULL,                        -- the member's Telegram user_id
    username      VARCHAR(255),                                 -- @username (may be missing/changing)
    joined_at     DATETIME     NOT NULL,                        -- join (UTC): real = NOW(); old-timer = '2000-01-01' (full tenure at once)
    score         DECIMAL(10,4) NOT NULL DEFAULT 0,             -- current activity score (recomputed by cron, full only)
    score_at      DATETIME,                                     -- when the score was last recomputed
    msg_count     INT          NOT NULL DEFAULT 0,              -- number of counted messages (full only)
    is_protected  TINYINT      NOT NULL DEFAULT 0,              -- 1 = protected, can't be put up for a vote
    banned_at     DATETIME,                                     -- when banned (NULL = active member)
    reentry_until DATETIME,                                     -- until this moment a re-entry means auto-kick (UTC)
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_user (chat_id, user_id),                 -- one member — one row per group
    KEY idx_chat_score (chat_id, score)                         -- for score selects/sorting
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- messages — message accounting (FULL mode ONLY). The text is NOT stored.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}messages (
    id              BIGINT AUTO_INCREMENT,                      -- surrogate PK
    chat_id         BIGINT   NOT NULL,                          -- which group
    user_id         BIGINT   NOT NULL,                          -- who sent it
    sent_at         DATETIME NOT NULL,                          -- when it was sent (UTC) — the basis for score decay
    reply_to_msg_id BIGINT,                                     -- which message it replies to (for the repeated-reply filter)
    PRIMARY KEY (id),
    KEY idx_user_chat_time (chat_id, user_id, sent_at)          -- for score computation and TTL cleanup
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- votes — votes.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}votes (
    id                  BIGINT AUTO_INCREMENT,                  -- surrogate PK
    chat_id             BIGINT   NOT NULL,                      -- in which group the vote runs
    target_id           BIGINT   NOT NULL,                      -- who is voted against (target user_id)
    initiator_id        BIGINT   NOT NULL,                      -- who initiated it (user_id)
    trigger_msg_id      BIGINT,                                 -- message_id of the spam message that was replied to
    trigger_text        TEXT,                                   -- text of the spam message (for the journal)
    trigger_date        DATETIME,                               -- date of the spam message (UTC)
    vote_message_id     BIGINT,                                 -- message_id of the vote message in the chat
    started_at          DATETIME NOT NULL,                      -- when the vote started (UTC)
    readonly_at         DATETIME,                               -- when the target got readonly (NULL = never)
    finished_at         DATETIME,                               -- when it finished (NULL = active)
    status              ENUM('active','banned','declined','expired','cancelled') NOT NULL DEFAULT 'active', -- outcome
    used_threshold      DECIMAL(8,2),                           -- which ban threshold applied (regular or protected)
    PRIMARY KEY (id),
    KEY idx_chat_status (chat_id, status),                      -- quickly find a group's active votes
    KEY idx_target      (chat_id, target_id)                    -- search votes by target
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- vote_records — individual votes. UNIQUE: one vote per participant.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}vote_records (
    id        BIGINT AUTO_INCREMENT,                            -- surrogate PK
    vote_id   BIGINT   NOT NULL,                                -- which vote it belongs to
    voter_id  BIGINT   NOT NULL,                                -- who voted (user_id)
    direction ENUM('for','against') NOT NULL,                   -- "for" the ban / "against" the ban
    weight    DECIMAL(6,3) NOT NULL,                            -- this vote's weight (1.0 or elder_weight)
    voted_at  DATETIME NOT NULL,                                -- when they voted (UTC)
    PRIMARY KEY (id),
    UNIQUE KEY uq_vote_voter (vote_id, voter_id)                -- can't vote twice in the same vote
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- suspects — a suspect marker (created for every vote).
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}suspects (
    id                BIGINT AUTO_INCREMENT,                    -- surrogate PK
    chat_id           BIGINT  NOT NULL,                         -- which group
    user_id           BIGINT  NOT NULL,                         -- who the marker is on (usually the vote target)
    vote_id           BIGINT  NOT NULL,                         -- which vote the marker was created by
    is_elder_conflict TINYINT NOT NULL DEFAULT 0,               -- 1 = both initiator AND target are elders
    cleared           TINYINT NOT NULL DEFAULT 0,               -- 1 = an admin cleared the marker manually
    cleared_at        DATETIME,                                 -- when the marker was cleared (UTC)
    PRIMARY KEY (id),
    KEY idx_chat_user    (chat_id, user_id),                    -- marker history per member
    KEY idx_chat_cleared (chat_id, cleared)                     -- filter "uncleared" ones in the panel
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- cron_schedule — the cron task scheduler (next_run_at markers).
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}cron_schedule (
    task        VARCHAR(64) NOT NULL,                           -- task name, PK
    next_run_at DATETIME    NOT NULL,                           -- when the task is due (UTC)
    PRIMARY KEY (task)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- pending_setup — waiting for the bot to be added to a group (TTL 10 minutes).
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}pending_setup (
    user_id    BIGINT   NOT NULL,                               -- who pressed "add to a new group" (PK)
    started_at DATETIME NOT NULL,                               -- when the wait started (UTC), for the TTL
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- bot_messages — IDs of service messages for deferred deletion.
-- =====================================================================
CREATE TABLE IF NOT EXISTS {prefix}bot_messages (
    id          BIGINT AUTO_INCREMENT,                          -- surrogate PK
    chat_id     BIGINT   NOT NULL,                              -- which chat the message is in
    message_id  BIGINT   NOT NULL,                              -- message_id of the message in Telegram
    delete_at   DATETIME NOT NULL,                              -- when to delete it (UTC)
    PRIMARY KEY (id),
    KEY idx_delete_at (delete_at)                               -- select "due for deletion"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
