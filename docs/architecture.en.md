# Ostrakon — for developers

How the project is built, for anyone who wants to extend it: the file structure, the asynchronous
webhook, the task scheduler, and schema migrations. It has nothing to do with installation — that's
in [For the bot's host](for-hosters.en.md). For an overview, see the [README](../README.md).

---

## Project structure

```
/
├── index.php              # web front controller / router (the admin panel lives here)
├── webhook.php            # Telegram webhook: validates secret, enqueues update, returns 200
├── cron.php               # worker: drains the update queue + runs scheduled tasks
├── install.php            # installer (requirements → migrations → webhook) — delete after install
├── php-logrotate.php      # log rotation by size/daily (config php-logrotate.conf, N archives,
│                          #   optional gzip; state in php-logrotate.state)
├── log.php                # dev: log viewer (?token=...&n=200 / &clear=1)
├── inspect.php            # dev: DB inspector/editor (cookie auth)
├── .htaccess              # Apache: deny listing, protect service files, rewrite to index.php
├── assets/                # web-accessible CSS/JS (Bulma + vanilla JS) for the panel
├── config/
│   ├── bot.example.php     # template → copy to bot.php
│   ├── db.example.php      # template → copy to db.php
│   └── defaults.php        # per-group defaults + instance settings + cron intervals
├── src/
│   ├── bootstrap.php       # autoloader + root path
│   ├── Config / DB / Bot / Logger / Lang / DevAuth
│   ├── Handler.php         # routes incoming updates
│   ├── GroupManager.php    # group lifecycle, participants, onboarding, per-user DM language
│   ├── VoteManager.php     # voting: initiate, tally, thresholds, finalize, timeouts
│   ├── ScoreManager.php    # full mode: message metadata, score recalc, elder tags/status
│   ├── Notifier.php        # personal DM notifications + reply-to-notification commands
│   ├── Exporter.php        # JSON export/import to move a group between instances
│   ├── Panel.php / PanelAuth.php   # web admin panel (router + Telegram Login auth)
│   ├── panel/              # server-rendered panel views (not web-accessible):
│   │                       #   home, group, participants, settings, journal, simulator,
│   │                       #   migration, privacy, erase, help, login, layout, error, superadmin
│   └── Migrator.php        # applies migrations
├── lang/
│   ├── 01-ru.php           # bot + panel texts (NN-<code>.php; order = filename)
│   ├── 02-kk.php           # Kazakh translation
│   └── 03-en.php           # English translation
├── migrations/
│   ├── run.php             # migration runner (?token=SUPERADMIN_TOKEN or CLI)
│   ├── 001_initial/        # base schema + cron seeding
│   ├── 002_update_queue/   # async webhook queue table
│   ├── 003_elder_tag/      # participants.elder_tagged flag
│   ├── 004_can_manage/     # participants.can_manage (settings-manager right)
│   ├── 005_users/          # per-user DM language
│   ├── 006_notify_prefs/   # participants notify_* flags
│   ├── 007_elder_status/   # participants.is_elder (status, independent of the tag)
│   ├── 008_notify_actions/ # reply-to-notification command context
│   ├── 009_group_active/   # groups.is_active (bot connected / removed)
│   ├── 010_vote_thread/    # votes.thread_id (forum topics: the vote posts in the trigger's topic)
│   ├── 011_onboarding/     # group onboarding state + users.has_dm; drops pending_setup
│   └── 012_onboarding_pending/ # groups.onboarding_pending (deferred supergroup onboarding)
└── logs/                   # app.log + cron lock file (web access denied)
```

> On **Nginx**, `.htaccess` is ignored — deny web access to `config/`, `src/`, `logs/`,
> `migrations/` (except `run.php`) and to `*.md`/`*.sql`/`*.log` in the server config, and route
> unknown paths to `index.php`.

`config/bot.php` and `config/db.php` hold secrets and **must not** be committed.

---

## Asynchronous webhook

Because shared hosting often lacks `fastcgi_finish_request`, the bot never processes updates inline
in the webhook request:

1. `webhook.php` validates the secret, stores the raw update in `update_queue` (deduplicated by
   `update_id`), returns `200` immediately, and best-effort "pokes" the worker via a short
   self-request.
2. `cron.php` is the **worker**: it holds a `flock` (no overlapping runs), loops for
   `worker_loop_seconds`, and every couple of seconds drains the queue (running `Handler::handle`
   per update) and runs any due scheduled tasks.

So responses are fast regardless of `fastcgi_finish_request`, and even an infrequent system cron
keeps things moving (the self-poke covers the gaps when it works).

The worker timing and the self-poke itself are tuned by the `instance` section of
`config/defaults.php` (`worker_loop_seconds`, `worker_poll_seconds`, `worker_self_poke`,
`worker_heartbeat`).

---

## Task scheduler (cron)

`cron.php` reads the `cron_schedule` table: tasks whose `next_run_at <= NOW()` run, then their
`next_run_at` is pushed by the interval from `config/defaults.php`. This fires each task on time and
exactly once even if the system cron runs less than once a minute.

Tasks: `vote_timeouts`, `onboarding_check`, `bot_messages_cleanup` (every minute), `reentry_check`
(5 min), `score_recalc`, `data_ttl` (daily). Overlap is prevented by a `flock` advisory lock on
`logs/do_not_delete_this.lock` (auto-released; never delete it).

---

## Schema migrations

Post-install schema changes go into `migrations/NNN_description/` folders (one action per
`*.sql`/`*.php` file). Folders apply in name order; a folder counts only if all its files succeed
(tracked in `{prefix}migrations`). Use `{prefix}` for table names in `.sql`.

- Web: `https://YOUR_DOMAIN/migrations/run.php?token=YOUR_SUPERADMIN_TOKEN`
- CLI: `php migrations/run.php`

---

## The elder math

The decaying activity score, the status thresholds, and the simulator formulas are in a separate
file: [Elder formulas](formulas.en.md).
