# Ostrakon — privacy

What data the bot stores and what it deliberately doesn't, how it ages out, and how to erase it. For
a project overview, see the [README](../README.md); for what the bot does, see
[For group owners and admins](for-admins.en.md).

---

## What is and isn't stored

The bot is deliberately data-minimal:

- **Stored:** `user_id`, `@username` (if any), the join timestamp, per-member aggregates (`score`,
  message **count**), ban flags, per-group settings, votes (with the text of the *triggering*
  message, kept for the moderation journal), and small per-user preferences (chosen DM language,
  per-admin notification opt-ins).
- **Not stored:** the text of ordinary messages and users' first/last names. In full mode only
  message **metadata** is recorded (who, when, in reply to what) — never the content.

---

## How data ages out

In full mode, message metadata isn't kept forever: the daily `data_ttl` task deletes records older
than `halflife_days × 4`. By that age a message's contribution to `score` has fallen to ~6.25% and
barely affects the rating (see the [Elder formulas](formulas.en.md)). The same task clears stale
helper context too (for example, the link between reply commands and their notifications).

---

## Public privacy notice

The bot has a **public privacy page** — it opens without login, reachable from the panel footer and
from the `/privacy` command in the DM with the bot. Its text comes from the `privacy_body` language
key, so you can adjust it for your instance without touching the code.

---

## Erasing a participant's data

A group's owner or a manager can fully **erase a specific participant's data** in that group: the
panel's group page has an "Erase a participant's data" section. The participant is looked up by
`@username` or numeric id, and then the deletion is confirmed per matching candidate. The action is
irreversible.
