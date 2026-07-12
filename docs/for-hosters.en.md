# Ostrakon — for the bot's host

Installing and operating an instance: requirements, @BotFather setup, deployment, config files,
security, and the operator page. What the bot does from the groups' side is in
[For group owners and admins](for-admins.en.md); the internals are in
[For developers](architecture.en.md). For an overview, see the [README](../README.md).

---

## Hosting requirements

| Component | Requirement |
|---|---|
| PHP | **8.1+** (developed on 8.2) |
| PHP extensions | `pdo_mysql`, `curl`, `json` (required); `mbstring` (recommended) |
| Database | MySQL 5.7+ **or** MariaDB 10.x (InnoDB, utf8mb4) |
| Web server | Apache (`.htaccess` included) or Nginx (lock down folders manually) + `mod_rewrite` |
| HTTPS | **Required** — the Telegram webhook and Login Widget only work over HTTPS |
| Cron | Ability to run `cron.php` on a schedule (every minute is ideal; less often also works) |

`fastcgi_finish_request` is **not required**: the webhook is asynchronous (it queues the update and
returns `200` immediately; a cron worker processes the queue — see
[Asynchronous webhook](architecture.en.md#asynchronous-webhook)).

> **Time is single-sourced from the database.** All timestamps are stored and compared in UTC, read
> from the DB server's clock (once per connection). PHP and the database may sit on different hosts
> with drifting clocks — it won't affect correctness.

Placement is configured by `APP_URL` and supports both:
- a subdomain: `https://ostrakon.example.com`
- a subfolder: `https://example.com/ostrakon`

### Security notes

- **`WEBHOOK_SECRET`** must be a long random string: anyone who learns it can POST forged updates to
  `webhook.php` (fake votes/bans). Optionally also restrict `webhook.php` to Telegram's IP ranges at
  the web-server level.
- **Delete `install.php`, `log.php`, `inspect.php`** after deploying; leave `DEV_TOKEN` empty.
- Keep `SUPERADMIN_TOKEN` out of URLs; the cron/worker uses the separate low-privilege
  `WORKER_TOKEN`.
- On **shared hosting**, point PHP sessions at a private directory inside the project
  (`session.save_path` + `open_basedir`) so other tenants can't read the panel's session files.
  `config/`, `src/`, `logs/` must be denied over HTTP — the bundled `.htaccess` does this on Apache;
  on Nginx configure it yourself.

---

## Bot setup (@BotFather)

The host's job at @BotFather is to create the bot, configure it globally, and (if needed) register
the webhook by hand. Adding the bot to groups and giving it rights there is the group owners'
concern, not the host's.

### 1. Create the bot
`/newbot` → set a display name and a username ending in `bot`. BotFather returns a **token** → put
it in `config/bot.php` (`BOT_TOKEN`).

### 2. Configure the bot
- **Disable privacy mode:** `/setprivacy` → pick the bot → **Disable**. Do this **always** and
  **before anyone starts adding the bot to groups**: you can't know in advance which owners will
  want full mode, and with privacy ON the bot doesn't see ordinary messages and can't count
  activity/score. (Vote initiation via an `@mention` works regardless of privacy.)
- **Login Widget domain** (for the panel): `/setdomain` → pick the bot → the host from `APP_URL`
  (e.g. `ostrakon.example.com`, host only — no `https://`, no path).
- **Profile picture** (optional): `/setuserpic` → pick the bot → upload a square image (Telegram
  crops it to a circle). You can use `assets/favicon.png` (512×512). The display name,
  `/setdescription`, and `/setabouttext` can be set here too.

### 3. Webhook
The installer registers the webhook automatically from `APP_URL` + `WEBHOOK_SECRET`. Do it by hand
only if the automatic registration didn't work:

```bash
curl -F "url=https://YOUR_DOMAIN/webhook.php" \
     -F "secret_token=YOUR_WEBHOOK_SECRET" \
     -F 'allowed_updates=["message","callback_query","chat_member","my_chat_member"]' \
     "https://api.telegram.org/botYOUR_TOKEN/setWebhook"
```

> `allowed_updates` must include `chat_member` and `my_chat_member`, otherwise the bot won't see
> joins/leaves/promotions. The installer sends the correct set.

---

## Deployment

1. **Upload** the project to the directory matching `APP_URL`.
2. **Create** a utf8mb4 database and a user.
3. **Create configs** from the templates and fill them in:
   - `cp config/bot.example.php config/bot.php` — `BOT_TOKEN`, `BOT_USERNAME`, `WEBHOOK_SECRET`,
     `APP_URL`, `LOG_LEVEL`. Leave `SUPERADMIN_TOKEN` and `SUPERADMIN_PATH` empty — the installer's
     first run sets them (see below).
   - `cp config/db.example.php config/db.php` — DB credentials, `DB_TABLE_PREFIX`.
4. **Set up the bot** at @BotFather (steps 1–2 above: create and configure).
5. **Run the installer** — it checks requirements, applies **migrations**, registers the webhook and
   the bot's DM command menu (no need to set the commands by hand in @BotFather):
   - Web, first run (empty `SUPERADMIN_TOKEN`): open `https://YOUR_DOMAIN/install.php` — no token
     needed; a form creates the superadmin login/password and writes the config, then the install
     continues.
   - Web, later runs: `https://YOUR_DOMAIN/install.php?token=YOUR_SUPERADMIN_TOKEN`
   - CLI: `php install.php`
   - Skip webhook registration with `?webhook=0` (web) / `php install.php nowebhook` (CLI).
6. **⚠️ Delete `install.php`** after a successful install.
7. **Add a system cron** for `cron.php` (every minute is ideal):
   - CLI: `* * * * * /usr/bin/php /full/path/to/cron.php >/dev/null 2>&1`
   - or URL: `https://YOUR_DOMAIN/cron.php?token=YOUR_WORKER_TOKEN` (for a URL-cron service that
     can't send headers; the low-privilege `WORKER_TOKEN` is used here on purpose — never
     `SUPERADMIN_TOKEN`)
8. Make sure `logs/` is writable by the web server.
9. **Delete the dev tools** `log.php` and `inspect.php` in production (debugging only). As a safety
   net they also stay disabled unless `DEV_TOKEN` is set in `config/bot.php` (empty by default).

The installer is idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, tracked migrations) —
re-running it is safe.

---

## Configuration

### `config/bot.php`
| Key | Purpose |
|---|---|
| `APP_URL` | Full public address (host+path, no trailing `/`); the single source of all URLs |
| `BOT_TOKEN` | Token from @BotFather |
| `BOT_USERNAME` | Bot username without `@` (Login Widget + mention detection) |
| `WEBHOOK_SECRET` | Verified against the `X-Telegram-Bot-Api-Secret-Token` header on every update. **Use a long random value** — anyone who learns it can forge updates (fake votes/bans) |
| `WORKER_TOKEN` | Low-privilege secret that ONLY triggers the worker (`cron.php`). The webhook self-poke sends it in the `X-Ostrakon-Token` header; URL-cron services pass it as `?token=`. Kept separate from `SUPERADMIN_TOKEN` so a cron URL in access logs can't leak the master secret. Generated on the first `install.php` run |
| `SUPERADMIN_TOKEN` | `base64("login:password")`. Gates `install.php`/`migrations/run.php` over HTTP, the dev tools, **and** is the HTTP Basic Auth credential for the operator page. Keep it OUT of URLs. Set on the first `install.php` run |
| `SUPERADMIN_PATH` | Secret slug of the operator page → `APP_URL/<slug>`; empty disables it (see "Operator page" below). **Must not contain `admin`** — such a slug would collide with the panel and is ignored |
| `DEV_TOKEN` | Dedicated secret for the debug endpoints `log.php`/`inspect.php` (separate from `SUPERADMIN_TOKEN`). **Empty by default → those endpoints are disabled** (answer `404` even if left on the server). Set a random value only while debugging |
| `LOG_LEVEL` | `trace`/`debug`/`info`/`warning`/`error`/`fatal` |

### `config/db.php`
Connection settings and `DB_TABLE_PREFIX` (e.g. `vb_`).

### `config/defaults.php`
Per-group defaults (copied into `groups` when the bot is added), instance settings (`history_days`,
worker loop timing), and cron intervals. See the file's comments.

---

## Operator page (superadmin)

If you run this bot for others, there's a separate operator page listing every group the bot serves
— including ones it was removed from — each with its last activity, and, for a selected group, its
owner and admins (bots excluded). It's meant for reaching group owners/admins directly, e.g. to warn
them before shutting the service down. The operator needs to know **nothing about Telegram**: the
data is fetched server-side with the bot token, and this page has nothing to do with the Telegram
login.

The page lives at a **secret address you choose** — `SUPERADMIN_PATH` in `config/bot.php` (a random
slug → `APP_URL/<slug>`) — and is protected by **HTTP Basic Auth** (`SUPERADMIN_TOKEN`, stored as
`base64("login:password")`). Two independent layers: a secret URL and a password. Leave
`SUPERADMIN_PATH` empty to disable the page entirely (any request is then a plain `404`).

Set the credentials on the **first run of `install.php`**: while `SUPERADMIN_TOKEN` is still empty
the installer needs no token and instead shows a short form to create the login/password — it writes
`SUPERADMIN_TOKEN` and a random `SUPERADMIN_PATH` into `config/bot.php` for you (make
`config/bot.php` writable, or paste the shown values manually). With shell access,
`php install.php gentoken` prints the same values to paste. On CGI/FastCGI hosting, HTTP Basic Auth
needs the `Authorization` header passed to PHP — the bundled `.htaccess` already does this
(`CGIPassAuth On` is the Apache alternative).

---

## Logging & dev tools

`logs/app.log` (UTC). Level via `LOG_LEVEL`:
`FATAL(60) > ERROR(50) > WARNING(40) > INFO(30) > DEBUG(20) > TRACE(10)` — messages at or above the
threshold are written. Use `debug`/`trace` while debugging, `warning`/`error` in production.

Dev-only helpers — **delete them in production**: `log.php` (view/clear the log) and `inspect.php`
(inspect/edit the DB — arbitrary SQL). They are gated by their own `DEV_TOKEN` (separate from
`SUPERADMIN_TOKEN`) and are **disabled unless `DEV_TOKEN` is set** — empty by default, so they
answer `404` even if the files remain. First visit with `?token=DEV_TOKEN` sets a cookie. CLI use is
always allowed.
