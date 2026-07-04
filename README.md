# Ostrakon

A Telegram bot for **democratic banning of spammers** in group chats: members vote to
kick a suspect, and the bot enforces the outcome. Pure PHP, no frameworks, designed to
run on ordinary shared hosting.

- Full specification (Russian): [`SPEC.md`](SPEC.md)
- Reputation ("elder") formulas (Russian): [`FORMULAS.md`](FORMULAS.md)
- Russian README (may lag behind this one): [`README_RUS.md`](README_RUS.md)

> **Status: feature-complete, pending real-world testing.** Working: infrastructure,
> asynchronous webhook, voting (light + full mode with activity score and "elder" status),
> the multilingual web admin panel with per-admin access control, personal DM notifications
> (with reply-to-act commands), all cron jobs, and JSON export/import (`Exporter`) for moving
> a group between instances.

---

## How it works

- **In the group, the bot does exactly one thing: it starts a vote.** A member replies
  to the offender's message with **just** the bot's mention (`@yourbot` and nothing else) —
  the bot posts a vote with "For / Against" buttons. (Requiring the bare mention avoids an
  explanatory sentence that merely contains `@yourbot` accidentally voting someone out; if the
  bot is mentioned any other way it just replies with a short how-to.) There are **no commands to type in the group** (nothing to clutter
  it, and it stays safe when several bots share a chat — you mention the specific one). The
  bot's only commands are in its DM: `/start`, `/groups`, `/language` and `/help`, plus
  reply-to-notification moderation — see the panel section.
- **Everything else is managed from the web admin panel** (`/admin`, login via Telegram):
  protect members, unban, edit group settings, view the vote journal, tune the "elder"
  parameters.
- **Vote outcome.** Votes are summed by *weight*. Reaching the ban threshold kicks the
  user; reaching the decline threshold closes the vote. An **admin** decides instantly by
  pressing a button. Timeouts (T1/T2) close stale votes.
- **Two modes per group:**
  - **light** — only voting; no message tracking.
  - **full** — additionally counts activity and computes a decaying **score**; members
    above `elder_threshold` become **"elders"** whose vote carries more weight and who get
    a visible Telegram tag next to their name.

---

## Privacy — what is and isn't stored

The bot is deliberately data-minimal:

- **Stored:** `user_id`, `@username` (if any), join timestamp, per-member aggregates
  (`score`, message **count**), ban flags, per-group settings, votes (with the text of the
  *triggering* message, kept for the moderation journal), and small per-user preferences
  (chosen DM language, per-admin notification opt-ins).
- **Not stored:** message text of ordinary messages, and users' first/last names. In full
  mode only message **metadata** is recorded (who, when, reply-to) — never the content.

---

## Hosting requirements

| Component | Requirement |
|---|---|
| PHP | **8.1+** (developed on 8.2) |
| PHP extensions | `pdo_mysql`, `curl`, `json` (required); `mbstring` (recommended) |
| Database | MySQL 5.7+ **or** MariaDB 10.x (InnoDB, utf8mb4) |
| Web server | Apache (`.htaccess` included) or Nginx (lock down folders manually) + `mod_rewrite` |
| HTTPS | **Required** — Telegram webhook and Login Widget only work over HTTPS |
| Cron | Ability to run `cron.php` on a schedule (every minute is ideal; less often also works) |

`fastcgi_finish_request` is **not required**: the webhook is asynchronous (it queues the
update and returns `200` immediately; a cron worker processes the queue — see
[Asynchronous webhook](#asynchronous-webhook)).

> **Time is single-sourced from the database.** All timestamps are stored and compared in UTC,
> read from the DB server's clock (once per connection). PHP and the database may sit on
> different hosts with drifting clocks — it won't affect correctness.

Placement is configured by `APP_URL` and supports both:
- a subdomain: `https://ostrakon.example.com`
- a subfolder: `https://example.com/ostrakon`

---

## Project structure

```
/
├── index.php              # web front controller / router (the admin panel lives here)
├── webhook.php            # Telegram webhook: validates secret, enqueues update, returns 200
├── cron.php               # worker: drains the update queue + runs scheduled tasks
├── install.php            # installer (requirements → migrations → webhook) — delete after install
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
│   ├── panel/              # server-rendered panel views (not web-accessible)
│   └── Migrator.php        # applies migrations
├── lang/
│   ├── 01-ru.php           # bot + panel texts (NN-<code>.php; order = filename)
│   ├── 02-en.php           # English translation
│   └── 03-kk.php           # Kazakh translation
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
│   └── 009_group_active/   # groups.is_active (bot connected / removed)
└── logs/                   # app.log + cron lock file (web access denied)
```

> On **Nginx**, `.htaccess` is ignored — deny web access to `config/`, `src/`, `logs/`,
> `migrations/` (except `run.php`) and to `*.md`/`*.sql`/`*.log` in the server config, and
> route unknown paths to `index.php`.

`config/bot.php` and `config/db.php` hold secrets and **must not** be committed.

---

## Bot setup (@BotFather)

### 1. Create the bot
`/newbot` → set a display name and a username ending in `bot`. BotFather returns a
**token** → goes into `config/bot.php` (`BOT_TOKEN`).

### 2. Configure the bot
- **Privacy mode — for `full` mode you MUST disable it:** `/setprivacy` → pick the bot →
  **Disable**. With privacy ON the bot doesn't see ordinary messages, so activity/score
  can't be counted. (Vote initiation via an `@mention` works either way.) Privacy changes
  apply only after the bot **rejoins**: after Disable, remove the bot from the group and
  add it back.
- **Login Widget domain** (for the admin panel): `/setdomain` → pick the bot → the host
  from `APP_URL` (e.g. `ostrakon.example.com`, host only — no `https://`, no path).
- **Profile picture** (optional): `/setuserpic` → pick the bot → upload a square image
  (Telegram crops it to a circle). You can use `assets/favicon.png` (512×512). The display
  name and an `/setdescription` / `/setabouttext` can be set here too.

### 3. Bot rights in the group
Add the bot as an **administrator** (being an admin is required to receive member
join/leave events and to ban). Enable **only** these rights, turn the rest off:
- **Ban users** — to ban and to apply read-only during a vote.
- **Delete messages** — to remove the spam/activation messages.
- **Manage member tags** (`can_manage_tags`) — to show the "elder" tag in full mode.
  Optional if you don't use full-mode elder tags.

### 4. Webhook
`install.php` registers it automatically from `APP_URL` + `WEBHOOK_SECRET`. Manual
registration (only if needed):

```bash
curl -F "url=https://YOUR_DOMAIN/webhook.php" \
     -F "secret_token=YOUR_WEBHOOK_SECRET" \
     -F 'allowed_updates=["message","callback_query","chat_member","my_chat_member"]' \
     "https://api.telegram.org/botYOUR_TOKEN/setWebhook"
```

> `allowed_updates` must include `chat_member` and `my_chat_member`, otherwise the bot
> won't see joins/leaves/promotions. The installer sends the correct set.

---

## Deployment

1. **Upload** the project to the directory matching `APP_URL`.
2. **Create** a utf8mb4 database and a user.
3. **Create configs** from templates and fill them in:
   - `cp config/bot.example.php config/bot.php` — `BOT_TOKEN`, `BOT_USERNAME`,
     `WEBHOOK_SECRET`, `APP_URL`, `SUPERADMIN_TOKEN`, `LOG_LEVEL`.
   - `cp config/db.example.php config/db.php` — DB credentials, `DB_TABLE_PREFIX`.
4. **Set up the bot** at @BotFather (steps 1–3 above).
5. **Run the installer** — checks requirements, applies **migrations**, registers the webhook
   and the bot's DM command menu (`/start`, `/groups`, `/language`, `/help` — no need to set
   them by hand in @BotFather):
   - Web: `https://YOUR_DOMAIN/install.php?token=YOUR_SUPERADMIN_TOKEN`
   - CLI: `php install.php`
   - Skip webhook registration with `?webhook=0` (web) / `php install.php nowebhook` (CLI).
6. **⚠️ Delete `install.php`** after a successful install.
7. **Add a system cron** for `cron.php` (every minute is ideal):
   - CLI: `* * * * * /usr/bin/php /full/path/to/cron.php >/dev/null 2>&1`
   - or URL: `https://YOUR_DOMAIN/cron.php?token=YOUR_SUPERADMIN_TOKEN`
8. Make sure `logs/` is writable by the web server.
9. (Optional) Remove the dev tools `log.php` and `inspect.php` in production.

The installer is idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, tracked
migrations) — re-running it is safe.

---

## Configuration

### `config/bot.php`
| Key | Purpose |
|---|---|
| `APP_URL` | Full public address (host+path, no trailing `/`); the single source of all URLs |
| `BOT_TOKEN` | Token from @BotFather |
| `BOT_USERNAME` | Bot username without `@` (Login Widget + mention detection) |
| `WEBHOOK_SECRET` | Verified against the `X-Telegram-Bot-Api-Secret-Token` header |
| `SUPERADMIN_TOKEN` | Gate for `cron.php`/`install.php`/`migrations/run.php` over HTTP, and internal worker self-trigger |
| `LOG_LEVEL` | `trace`/`debug`/`info`/`warning`/`error`/`fatal` |

### `config/db.php`
Connection settings and `DB_TABLE_PREFIX` (e.g. `vb_`).

### `config/defaults.php`
Per-group defaults (copied into `groups` when the bot is added), instance settings
(`history_days`, worker loop timing), and cron intervals. See the file's comments.

---

## Web admin panel

Open `APP_URL/admin` and **log in with Telegram** (Login Widget; the signature is verified
with `BOT_TOKEN`). No passwords are entered anywhere. Sections:

- **My groups** — groups where you are an admin and the bot is present.
- **Participants** — search / multi-column sort / paginate; protect / unprotect / unban;
  in full mode an "elder %" column and admin/owner badges. The owner can also grant/revoke a
  "manager" right here (per-admin) — see access control below.
- **Settings** — every group setting (mode, thresholds, timeouts, elder parameters, etc.),
  grouped and validated. **Manager-only** (owner or a granted admin).
- **Journal** — vote history with status filter and search.
- **Simulator** (full mode, **manager-only**) — from the group's real activity, pick the
  `elder_threshold` that makes a target share of active members "elders" within a horizon.
- **Migration** (**manager-only**) — export the whole group to a JSON file and import it
  into the same group on another instance. See "Migration between instances" below.

**Access control.** Any group admin can protect/unprotect/unban and read the journal. Only
the **owner** (creator) can change settings, use the simulator, and run migration — plus any
admin the owner explicitly grants the *manager* right (stored per group). Other admins cannot
grant it. A demoted admin loses the right automatically (it only applies while they're an
admin). Grant/revoke is done from the Participants list and is owner-only.

Also, only the group **owner** can connect the bot: Telegram often lets ordinary members add
bots, so on the first add the bot checks that whoever added it is the creator — otherwise it
posts a short notice and leaves. On a legitimate first add it posts a short, self-deleting hint
(removed after ~10 min) asking the owner to (1) open a DM with the bot to set it up and (2) make
it an administrator with the right to ban members — without that right the bot can't enforce a
vote.

Every state-changing action re-checks server-side that you administer *that* group, and
POSTs are CSRF-protected.

**Interface language.** The panel header has a flat language switcher (one link per available
language, shown before and after login). For a **logged-in** user the choice is stored in the
database (`users.lang`) and is the single source of truth — it's shared with the bot DM, so
changing the language on the site or via `/language` in the DM updates both (each change is
timestamped; the most recent one wins). The `ostrakon_lang` cookie only carries an on-site
choice made before login (or from another browser); at login it's reconciled with the stored
value by timestamp. Before any choice exists, the browser's `Accept-Language` is used, then the
default (the first language file). To add a language, drop a `lang/NN-<code>.php` file with the
same keys (`_language_name` / `_language_code` inside set its display name and code; `NN` in the
filename sets the order — the first file is the default).

**Personal notifications.** Any admin can opt in (per group, on the group page) to receive DMs
from the bot about: a vote starting (who vs whom), a vote outcome (banned / declined / expired /
cancelled), and elders appearing/disappearing. It's off by default and each admin controls only
their own — the owner can't toggle it for others. Delivery is a DM, so it only works if the admin
has an open chat with the bot; when they enable a notification the panel sends a test DM and, if
it can't reach them, prompts them to open a chat. The DM language is the user's own: they pick it
from a keyboard on the first `/start` (or later with `/language`), and it's also captured from the
panel when they tick a box. Elder-status notifications fire even when the visible elder tag is off.

**Quick actions from the DM.** An admin can moderate by *replying* to a notification (no need to
open the panel, and without cluttering the group). Reply to a vote-start notification with
`forceban`, `cancelban`, or `protect` (cancel the vote and protect the target); reply to a ban
notification with `unban` or `protect`. The bot checks you still administer that group and that the
vote is still open, then acts and confirms.

---

## Modes: light vs full

- **light** — the bot only handles vote initiation. No message tracking; privacy mode may
  stay ON.
- **full** — additionally records message **metadata** (never content) that passes the
  quality filters (`min_msg_length`, `msg_cooldown_minutes`) and periodically recomputes a
  decaying activity **score** (`score_recalc`). Members with `score ≥ elder_threshold`
  become **elders**: their vote weight is `elder_weight`, and (if `elder_title` is set)
  they receive a visible Telegram member tag, applied/removed automatically as they cross
  the threshold. Requires privacy OFF and the `can_manage_tags` right. Score decay follows
  `FORMULAS.md` (`score = Σ exp(−λ·age)`, `λ = ln2 / halflife_days`).

  **Manual elder appointment** (Participants list, any admin, full mode): makes a member an
  elder *now* by backfilling a fake message history (using the group's current settings) whose
  decayed score reaches `elder_threshold`. The score stays genuine — it survives recalculation
  and then **decays**, so keeping the status depends on real activity afterwards. No undo.

---

## Asynchronous webhook

Because shared hosting often lacks `fastcgi_finish_request`, the bot never processes
updates inline:

1. `webhook.php` validates the secret, stores the raw update in `update_queue`
   (deduplicated by `update_id`), returns `200` immediately, and best-effort "pokes" the
   worker via a short self-request.
2. `cron.php` is the **worker**: it holds a `flock` (no overlapping runs), loops for
   `worker_loop_seconds`, and every couple of seconds drains the queue (running
   `Handler::handle` per update) and runs any due scheduled tasks.

So responses are fast regardless of `fastcgi_finish_request`, and even an infrequent
system cron keeps things moving (the self-poke covers the gaps when it works).

---

## Task scheduler (cron)

`cron.php` reads the `cron_schedule` table: tasks whose `next_run_at <= NOW()` run, then
their `next_run_at` is pushed by the interval from `config/defaults.php`. This fires each
task on time and exactly once even if the system cron runs less than once a minute.

Tasks: `vote_timeouts`, `pending_setup_ttl`, `bot_messages_cleanup` (every minute),
`reentry_check` (5 min), `score_recalc`, `data_ttl` (daily). Overlap is prevented by a
`flock` advisory lock on `logs/do_not_delete_this.lock` (auto-released; never delete it).

---

## Schema migrations

Post-install schema changes go into `migrations/NNN_description/` folders (one action per
`*.sql`/`*.php` file). Folders apply in name order; a folder counts only if all its files
succeed (tracked in `{prefix}migrations`). Use `{prefix}` for table names in `.sql`.

- Web: `https://YOUR_DOMAIN/migrations/run.php?token=YOUR_SUPERADMIN_TOKEN`
- CLI: `php migrations/run.php`

---

## Logging & dev tools

`logs/app.log` (UTC). Level via `LOG_LEVEL`:
`FATAL(60) > ERROR(50) > WARNING(40) > INFO(30) > DEBUG(20) > TRACE(10)` — messages at or
above the threshold are written. Use `debug`/`trace` while debugging, `warning`/`error` in
production.

Dev-only helpers (consider removing in production): `log.php` (view/clear the log) and
`inspect.php` (inspect/edit the DB). Both are gated by `SUPERADMIN_TOKEN` → cookie.

---

## Migration between instances

A group can move to another instance of the bot (self-hosting, or leaving a public instance)
without losing its history. The `chat_id` is **not** remapped — it's the same Telegram group,
just served by a new bot.

1. Owner opens **Migration** in the panel and downloads the group's JSON export (settings,
   participants, message stats, finished-vote history). The bot token and **active** votes are
   never exported — active votes keep running wherever they are.
2. Owner adds the new bot to the same group (any mode) so voting can already work.
3. Owner uploads the export on the new instance's **Migration** page. The import is
   **additive**: history is merged in next to whatever the new bot already accumulated, and
   ordering by date puts old records in their natural place. In full mode the score and elder
   status are recomputed immediately after import, so existing elders show up right away
   (rather than only after the next daily recalculation).

Import is idempotent (safe to re-run): `groups`/`participants` upsert by their natural key;
`messages` and `votes` are de-duplicated by their timestamps, so the same file won't double
the history. Both instances must be on the **same export format version** (no auto format
migration) — otherwise the import is refused with a clear message.

---

## Roadmap

All planned features are implemented. Future work is limited to hosting tests and polish.

---

## License

Ostrakon is free software under the **GNU Affero General Public License v3.0 or later**
(`AGPL-3.0-or-later`) — see [`LICENSE`](LICENSE). Because the bot runs as a network service, the
AGPL's defining clause applies: if you run a modified version for others (e.g. as a public bot),
you must make your modified source available to its users.

**Dual licensing.** The author doesn't monetize Ostrakon — but if you want to use it in a *closed*
or commercial product without the AGPL's copyleft obligations, a separate commercial license is
available: open an issue on the project's GitHub to arrange it. (In other words: fork it, run it,
improve it freely — but if you close it and make money, share back.)

Copyright © 2026 Vladimir Ignatov.
