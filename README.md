# nox-site

Venue site for nøx — Нижньоюрківська 31, Київ. Lives at **noxpl4ce.com**.

## Stack

No build step. `index.html` is the whole front end (inline CSS + vanilla JS).
Backend is plain PHP in `api/`, matching what the shared host can run.

- `api/_venue.php` — single source of truth for the venue, its spec and the calendar
- `api/events.php` — `GET /api/events` → JSON for the page
- `api/rent.php` — `POST /api/rent` → rental enquiry, stored on disk and emailed
- `api/_mail.php` — Resend sender
- `api/_config.php` — loads secrets from outside the web root, logging, throttle

## Adding an event

Edit `nox_events()` in `api/_venue.php`. The upcoming/past split is computed from
`date` / `dateEnd` against today in Europe/Kyiv, so a passed event moves itself.

## Config

Secrets live in `/home/noxplcec/nox_config.php` (chmod 600), outside the web root.
Create it from `config.sample.php`. Nothing sensitive belongs in this repo.

## Deploy

Host: cPanel account `noxplcec` on uashared43, primary domain **noxpl4ce.com**,
docroot `/home/noxplcec/public_html`. The repository is cloned on the server at
`~/repositories/nox-site` and tracks `main`.

cPanel never pulls from GitHub on its own — a push here does nothing to the
server. After pushing: cPanel → Git™ Version Control → Manage → Pull or Deploy →
**Update from Remote**, then **Deploy HEAD Commit**. `.cpanel.yml` takes its
DEPLOYPATH from `$HOME`, so it follows the account and needs no editing.
