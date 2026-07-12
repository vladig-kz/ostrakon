# Ostrakon — for group owners and admins

What the bot does and how to use it from the group and the web panel. If you're **hosting** the
bot on your own server, see [For the bot's host](for-hosters.en.md). For a project overview, see
the [README](../README.md).

---

## How the bot works in a group

In a group the bot does exactly one thing — it **starts a vote**. To put an offender to a vote, a
member replies to their message and writes **only** the bot's mention — `@yourbot` and nothing
else. The bot posts a vote with "For / Against" buttons; in forum groups it goes into the same
topic as the trigger message.

Why "only the mention": this way an explanatory sentence that merely contains `@yourbot` won't
accidentally put someone up for a vote. If the bot is mentioned any other way, it just replies with
a short how-to.

There are **no commands to type in the group** — that keeps the chat uncluttered and stays safe
when several bots share it (you mention the specific one). All of the bot's commands are in its DM:

- `/start` — begin the dialog and pick a language;
- `/groups` — list your groups that have the bot;
- `/language` — change the language;
- `/help` — a short reference;
- `/privacy` — the privacy notice (what data the bot stores).

Plus moderation by **replying to a notification** — see "Quick actions from the DM" below.

**Vote outcome.** Votes are summed by *weight*. Once the "for" total reaches the ban threshold the
user is kicked; once "against" reaches the decline threshold the vote is closed. An **admin**
decides instantly by pressing a button. Stale votes are closed by timeouts (T1/T2).

---

## Connecting the bot and its rights in the group

Only a group's **owner** (creator) can connect the bot. Telegram often lets ordinary members add
bots too, so on the first add the bot checks who added it: if it wasn't the creator, it posts a
short notice and leaves.

On a legitimate first add the bot posts a short hint that self-deletes after about 10 minutes,
asking the owner to:

1. open a DM with the bot to set the group up via the panel;
2. make the bot an **administrator with the right to ban members** — without that right the bot
   can't enforce a vote's outcome.

**Bot rights in the group.** Add the bot as an administrator (without admin rights it doesn't
receive member join/leave events and can't ban) and enable only what's needed, turning the rest
off:

- **Ban users** — to ban and to apply read-only mode during a vote;
- **Delete messages** — to remove spam and the bot's own service messages;
- **Manage member tags** (`can_manage_tags`) — to show the "elder" tag in full mode; skip it if you
  don't use elder tags.

> Full mode has one more prerequisite — the bot must have **privacy mode disabled**. That's a
> global bot setting at @BotFather, done by the host at install time; it isn't a group right.

---

## Modes: light vs full

The mode is set per group, on its settings page.

- **light** — the bot only starts votes; members' messages aren't tracked.
- **full** — in addition to voting, the bot records message **metadata** (who, when, in reply to
  what — but not the text) for messages that pass the quality filters (`min_msg_length`,
  `msg_cooldown_minutes`), and periodically recomputes a decaying activity score — **score** (the
  `score_recalc` task). Members whose `score ≥ elder_threshold` become **elders**: their vote
  weight is `elder_weight`, and (if `elder_title` is set) a visible tag appears next to their name,
  added and removed automatically as they cross the threshold. The mode requires the bot's privacy
  mode to be off (done by the host) and the `can_manage_tags` right in the group. For how the score
  decays, see the [Elder formulas](formulas.en.md) (`score = Σ exp(−λ·age)`,
  `λ = ln2 / halflife_days`).

**Manual elder appointment** (the participants list, available to any admin, full mode only) makes
a member an elder *right now*: the bot backfills a plausible message history for them (using the
group's current settings) whose decayed score reaches `elder_threshold`. That score is genuine — it
survives the next recalculation and then **decays**, so keeping the status depends on real activity
afterwards. There's no undo.

---

## Web admin panel

Open `APP_URL/admin` and **log in with Telegram** (Login Widget; the signature is verified with the
bot token). No passwords are entered or stored anywhere. Sections:

- **My groups** — groups where you're an admin and the bot is present.
- **Participants** — search, sort by column, paginate; protect / unprotect from votes and unban; in
  full mode an "elder %" column and "admin" / "owner" badges. This is also where the owner grants
  and revokes the "manager" right — see "Who can do what" below.
- **Settings** — every group setting (mode, thresholds, timeouts, elder parameters, etc.), grouped
  and validated. Available to the **owner or a manager**.
- **Journal** — vote history with a status filter and search.
- **Simulator** (full mode, **owner or a manager**) — from the group's real activity, picks the
  `elder_threshold` at which a target share of active members become elders within a chosen horizon.
- **Erase a participant's data** (**owner or a manager**) — find a participant by `@username` or id
  and delete all of their data in this group. See also [Privacy](privacy.en.md).
- **Migration** (**owner only**) — export the whole group to a JSON file and import it into the same
  group on another instance. See "Migration between instances" below.

### Who can do what

- **Any group admin** — protect members from votes, unprotect, unban, read the journal, and in full
  mode appoint elders manually.
- **Manager** — an admin the owner has granted the special right (stored per group).
- **The owner or a manager** — everything any admin can do, plus group settings, the simulator, and
  erasing a participant's data.
- **The owner only** — grants and revokes the manager right (from the Participants page), performs
  migration, and, as noted above, connects the bot to the group.

The manager right applies only while the person remains a group administrator: it's checked live on
every action, so if someone loses their Telegram admin rights, they lose the manager right along
with them. Admins other than the owner can't grant this right.

Every state-changing action is re-checked server-side: you must be an admin of *that* group. POST
requests are CSRF-protected.

### Interface language

The panel header has a language switcher (one link per available language, shown both before and
after login). For a **logged-in** user the choice is stored in the database (`users.lang`) and is
the single source of truth: it's shared with the bot's DM, so changing the language on the site or
via `/language` in the DM updates it in both places at once (each change is timestamped; the most
recent one wins). The `ostrakon_lang` cookie only holds a choice made on the site before login (or
from another browser) — at login it's reconciled with the stored value by timestamp. Until a choice
exists, the browser's `Accept-Language` is used, and failing that the default language (the first
language file).

### Personal notifications

Any admin can enable, for each group (on its page), personal notifications from the bot: a vote
starting (who vs whom), an outcome (banned / declined / expired / cancelled), and elders appearing
or disappearing. Everything is off by default, and each admin controls only their own — the owner
can't enable them for others.

Notifications arrive in the DM with the bot, and the bot can't message a user first — so they only
work for people who have opened a chat with it. The first time you enable any notification, the
panel sends a test message to your DM; if it doesn't arrive, the panel asks you to open a chat with
the bot first and then try again. The notification language is each person's own: it's picked from a
keyboard on the first `/start` (or later via `/language`) and remembered from the panel when you
tick a box. Elder-status notifications arrive even if the visible tag is turned off in the group.

### Quick actions from the DM

An admin can moderate simply by **replying** to a notification — without opening the panel or
cluttering the group:

- in reply to a vote-start notification: `forceban` (ban immediately), `cancelban` (cancel the
  vote), or `protect` (cancel the vote and protect the member from future ones);
- in reply to a ban notification: `unban` or `protect` (unban and protect).

The bot checks that you're still an admin of that group (and, for a vote, that it's still open),
performs the action, and confirms.

---

## Migration between instances

A group can move to another instance of the bot (self-hosting, or leaving a public instance)
without losing its history. The `chat_id` does **not** change — it's the same Telegram group, just
served by a new bot. All of this is done by the group's **owner**:

1. Open **Migration** in the panel and download the group's JSON export (settings, participants,
   message stats, finished-vote history). The bot token and **active** votes are never exported —
   active votes keep running wherever they started.
2. Add the new bot to the same group (in any mode) so voting already works.
3. Upload the export on the new instance's **Migration** page. The import is **additive**: history
   is merged in next to whatever the new bot has already accumulated, and ordering by date puts old
   records in their natural place. In full mode the score and elder status are recomputed right
   after import, so existing elders show up immediately rather than only after the next daily
   recalculation.

Import is idempotent — safe to re-run: `groups` and `participants` upsert by their natural key,
while `messages` and `votes` are de-duplicated by their timestamps, so re-uploading the same file
won't double the history. Both instances must be on the **same export format version** (there's no
automatic format migration) — otherwise the import is refused with a clear message.

---

## Privacy

The bot deliberately collects a minimum of data: it doesn't store the text of ordinary messages or
first/last names. For the details — what exactly is stored, what isn't, how data ages out, and how
to erase a participant's data — see the separate [Privacy](privacy.en.md) file.
