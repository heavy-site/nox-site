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
- What the renter writes arrives in the venue's chat under «💬 #id · event ·
  name». **«💬 Відкрити діалог»** under it (or under the booking's card, or
  from `/chats`) puts that admin in the conversation: from then on whatever
  they write in the chat goes to the renter as the bot's own message — text,
  photos, files, voice — until `/end`. Each admin has their own open
  conversation. A reply to a renter's message still goes to that renter.
- Confirming, declining or cancelling a booking tells the renter on its own.
- «📜 Діалог» posts the whole conversation; `/admin` shows it under the booking
  and can send from there too. Everything is kept in the database.
- To answer as a team, add the bot to a group and send `/here` in it from the
  account in `TG_CHAT`: enquiries and conversations move there. `/b 12` brings
  booking #12's card with its buttons into the chat.

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
