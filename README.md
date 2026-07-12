# Ostrakon

A Telegram bot for **democratic banning of spammers** in group chats: members vote to
kick a suspect, and the bot enforces the outcome. Pure PHP, no frameworks, designed to
run on ordinary shared hosting.

Русская версия: [`README.ru.md`](README.ru.md).

> **Status: feature-complete, pending real-world testing.** Working: infrastructure,
> asynchronous webhook, voting (light + full mode with an activity score and "elder" status),
> the multilingual web admin panel with per-admin access control, personal DM notifications
> (with reply-to-act commands), all cron jobs, and JSON export/import (`Exporter`) for moving
> a group between instances.

---

## A bit of history

The name **Ostrakon** goes back to classical Athens of the 5th century BC and its procedure of
*ostracism* (*ostrakismós*): the people's assembly could banish a person from the city for ten
years if their influence seemed dangerous to the democracy. People voted by scratching the name
of the one to be exiled onto a shard of broken pottery — that shard is called an *ostrakon*
(*óstrakon*). The exile was not a punishment in the usual sense: the person lost neither
citizenship nor property and could return after ten years. This bot is a digital ostrakon (a
potsherd ballot): the community likewise decides together who has no place in the chat.

---

## Documentation

The material is split into files by **who it is for**:

- 👥 **[For group owners and admins](docs/for-admins.en.md)** — what the bot does: voting in the
  group, light/full modes and the "elder" status, the web panel, personal notifications, moving a
  group between instances.
- 🛠 **[For the bot's host](docs/for-hosters.en.md)** — installation and operation: requirements,
  @BotFather setup, deployment, config files, security, the operator page.
- 🧩 **[For developers](docs/architecture.en.md)** — internals: project structure, the asynchronous
  webhook, the task scheduler, schema migrations.
- 🔒 **[Privacy](docs/privacy.en.md)** — what data the bot stores and what it doesn't.
- 📐 **[Elder formulas](docs/formulas.en.md)** — the math of the decaying activity score.

---

## Hosting requirements (brief)

| Component | Requirement |
|---|---|
| PHP | **8.1+** (developed on 8.2) |
| PHP extensions | `pdo_mysql`, `curl`, `json` (required); `mbstring` (recommended) |
| Database | MySQL 5.7+ **or** MariaDB 10.x (InnoDB, utf8mb4) |
| Web server | Apache (`.htaccess` included) or Nginx (lock down folders manually) + `mod_rewrite` |
| HTTPS | **Required** — the Telegram webhook and Login Widget only work over HTTPS |
| Cron | Ability to run `cron.php` on a schedule (every minute is ideal; less often also works) |

`fastcgi_finish_request` is **not required**: the webhook is asynchronous. Full install and setup
instructions are in **[For the bot's host](docs/for-hosters.en.md)**.

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
