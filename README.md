# nox-site

Venue site for nøx — Нижньоюрківська 31, Київ.

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

Secrets live in `/home3/hevycom/nox_config.php` (chmod 600), outside the web root.
Create it from `config.sample.php`. Nothing sensitive belongs in this repo.

## Deploy

Push to GitHub, then pull via cPanel Git Version Control. `.cpanel.yml` copies the
site into the subdomain docroot. Confirm `DEPLOYPATH` matches what cPanel created
for the subdomain before the first deploy.
