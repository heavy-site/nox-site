# nox-site

Venue site for nøx — Нижньоюрківська 31, Київ. Lives at **noxpl4ce.com**.

## Stack

No build step. `index.html` is the whole front end (inline CSS + vanilla JS).
Backend is plain PHP in `api/`, matching what the shared host can run.

- `api/_venue.php` — the venue and its spec
- `api/_db.php` — the bookings: one SQLite file in `NOX_DATA_DIR`, made on first use
- `api/site.php` — `GET /api/site` → JSON for the pages, busy dates included
- `api/rent.php` — `POST /api/rent` → rental enquiry, stored on disk, in the database and sent to Telegram
- `api/telegram.php` — `POST /api/telegram` → the bot's webhook, for the buttons under an enquiry
- `api/_tg.php` — Telegram sender
- `api/_relay.php` — the bot between the venue and renters: linking, relaying, history
- `api/_config.php` — loads secrets from outside the web root, logging, throttle
- `admin.php` — `/admin`, the bookings, behind a password

## Bookings

Every enquiry from the form becomes a booking with the status *new*. In `/admin`
(or from the buttons under the enquiry in Telegram) it is confirmed or declined;
a confirmed booking can be cancelled. A confirmed night becomes *past* by itself
once its last day has gone, and an enquiry nobody answered before its date is
*expired* — nothing is moved by hand.

A confirmed day is held: the form says the date is taken as soon as it is picked,
the API refuses it, and a second booking cannot be confirmed onto it.

The enquiry files in `NOX_DATA_DIR/rent` stay the record. The database is built
from them — on its first run it takes in every file already there, and `/admin`
picks up any file the database missed.

## Adding an event

A night appears in the listing when its booking is confirmed and *Показати в
афіші* is ticked in `/admin`, with its title, line-up, tickets and poster. The
upcoming/past split is computed from the dates against today in Europe/Kyiv.

`nox_events()` in `api/_venue.php` is the calendar from before the database: it
seeds the database once, and it is what the site shows on a host without SQLite.

## Admin and the Telegram buttons

`/admin` is closed until `NOX_ADMIN_PASS` is set in `nox_config.php` — the
password itself, or better its hash:
`php -r 'echo password_hash("…", PASSWORD_DEFAULT);'`.

The buttons under enquiries in Telegram need the bot's webhook. Once the site is
deployed, press **Підключити** at the foot of `/admin`; it points the bot at
`/api/telegram` with a secret worked out from the token. Only presses from
`TG_CHAT` are acted on.

## Talking to renters through the bot

The venue's own contacts never reach a renter: two bots stand between them
(`api/_relay.php`). The venue's bot (`TG_TOKEN`) brings enquiries into the
venue's chat; the renters' bot (`TG_RENTER_TOKEN`, or its token entered at the
foot of `/admin`) is the only one renters see. Files are carried from one bot
to the other by the server, since bots cannot copy each other's messages.

- After the form is sent the site takes the visitor straight to the renters'
  bot tied to that booking (`t.me/<bot>?start=b<id>_<sig>`). A bot cannot
  write first, so their press of Start is what lets it answer. For a booking that did not come from the
  form, `/admin` shows the same link to send once.
- Enquiries and their buttons go to the venue's group (`TG_CHAT`/`TG_TOPIC`).
  Conversations do not: they happen in each admin's **private chat with the
  venue's bot**. An admin is anyone in that group (checked with Telegram once a
  day); they open the bot and press Start once.
- A renter's message reaches every admin who started the bot, with
  «💬 Відкрити діалог». Opening it (or picking from `/chats`) puts that admin
  in the conversation: the last messages are shown, and whatever they write
  in that private chat goes to the renter until `/end`. While someone has a
  conversation open, the renter's messages go only to them. The same button
  under a booking in the group opens the conversation in private.
- **Topics.** With topics turned on for the venue's bot in @BotFather
  (Threaded Mode), every renter becomes a topic of its own in each admin's
  private chat — «DOLLS RAVE · Олена» — opening with the last of what was
  said. Writing in a topic goes to that renter; what one admin sends shows in
  the others' topics too. No opening or closing. If Telegram refuses topics,
  the bot falls back to the conversations above for six hours and tries again.
- Confirming, declining or cancelling a booking tells the renter on its own.
- **Files from renters** (up to 20 MB, Telegram's limit for bots) are kept in
  `NOX_DATA_DIR/files`, outside the web root. `/admin` shows them in the
  conversation — a preview for pictures, a download for anything — only to a
  signed-in admin. A picture can become the night's poster in one press:
  «🖼 Зробити афішею» under it in Telegram or in `/admin`.
- **Posters** can also be uploaded on a booking's page in `/admin`. Either way
  the site makes 1080 and 720 px JPEGs in `/uploads/posters` (a folder the
  deploy never touches) and fills in both poster paths (`api/_poster.php`).
- «📜 Уся історія» posts the whole conversation; `/admin` shows it under the
  booking and can send from there too. Everything is kept in the database.
- Until any admin has started the venue's bot, renters' messages fall back to
  the group so nothing is lost.

## Config

Secrets live in `/home/noxplcec/nox_config.php` (chmod 600), outside the web root.
Create it from `config.sample.php` — it is not deployed and never overwritten, so it
has to be made by hand once. Nothing sensitive belongs in this repo.

The database, sessions of `/admin`, the enquiries and the log all live in
`NOX_DATA_DIR`, outside the web root.

An enquiry goes to Telegram and nowhere else; there is no mail. Every one is also
written to `NOX_DATA_DIR/rent` before anything is sent, so a submission survives the
bot being down, misconfigured, or not yet created.

## Deploy

Host: cPanel account `noxplcec` on uashared43, primary domain **noxpl4ce.com**,
docroot `/home/noxplcec/public_html`. The repository is cloned on the server at
`~/repositories/nox-site` and tracks `main`.

cPanel never pulls from GitHub on its own — a push here does nothing to the
server. After pushing: cPanel → Git™ Version Control → Manage → Pull or Deploy →
**Update from Remote**, then **Deploy HEAD Commit**. `.cpanel.yml` takes its
DEPLOYPATH from `$HOME`, so it follows the account and needs no editing.
