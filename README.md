# GlobeWatch

*English · [Italiano](#italiano)*

A live world map of armed conflicts, civil unrest, violence against civilians, natural disasters
and humanitarian/health emergencies, built entirely on open data. Mobile-first, English and Italian,
plain PHP + MySQL: it runs on ordinary shared hosting with no cron and no build step.

## Features

- **30+ open-data sources** (GDELT, HDX HAPI, GDACS, USGS, EMSC, NASA FIRMS/EONET, ReliefWeb, UCDP,
  WHO, IFRC, Copernicus EMS, NOAA, Meteoalarm, IODA, ACAPS, IDMC…). Each one is cached on its own and refreshed
  in the background, so every page load answers instantly.
- **Conflict intensity** computed from ACLED (paid) → HDX HAPI (free) → UCDP Candidate → relative GDELT
  media coverage. The detail panel always says which basis was used.
- **19 map layers**, category tabs, intensity filter, periods from 24 hours to 10 years, full-text search.
- Conflict detail with fatalities, media coverage, INFORM Risk, ACAPS severity, hunger, displacement, funding,
  charts, latest news and ReliefWeb reports.
- **Installable PWA**: add it to the home screen or desktop; offline page, cached app shell and last-known data when the network drops.
- **Admin panel** for one-click database installation, source switches and health, cache and the ACLED archive.

## Project structure

```text
├── index.php            front end (HTML shell)
├── admin.php            admin panel
├── manifest.webmanifest PWA manifest      sw.js  service worker      offline.html  offline page
├── assets/
│   ├── css/app.css      front end styles        css/admin.css  admin styles
│   ├── js/app.js        front end application   js/i18n.js     UI strings, sources, changelog
│   ├── icons/           PWA icons (generated)   img/favicon.svg
├── api/                 JSON API + back end library
│   ├── conflicts.php  events.php  conflict.php  status.php  banner.php   public endpoints
│   ├── refresh.php      optional warm-up for an external pinger
│   ├── diagnostics.php  source-by-source checks (admin only)
│   ├── lib.php  db.php  schema.php  builders.php  sources*.php  countries.php  version.php
│   └── config.php.example  → copy to config.php
├── sql/schema.sql       definitive database schema + seed data
├── tools/               generate-icons.sh · bump-version.sh
└── docs/                older release notes · branding/ (icon source)
```

Upload the whole repository to the web root (or a sub-folder). The `.htaccess` files block direct
access to `api/` internals, `sql/`, `docs/`, dotfiles and Markdown files on Apache 2.4.
**On nginx** add equivalent rules, for example:

```nginx
location ~ /\.                                        { deny all; }
location ~ ^/(sql|docs)/                               { deny all; }
location ~ ^/api/(config|lib|db|schema|builders|sources|countries|version)  { deny all; }
```

## Progressive Web App

GlobeWatch can be installed from Chrome/Edge/Android (menu → **Install app**) or from Safari on iOS
(Share → *Add to Home Screen*). It needs **HTTPS** (or `localhost`) and must be served from the folder that contains
`index.php`, `sw.js` and `manifest.webmanifest`.

- App shell (CSS, JS, icons) is precached; pages are network-first with an offline fallback (`offline.html`).
- API responses are network-first; offline, the last good response is shown. Map tiles are cached (capped).
- `admin.php`, diagnostics and refresh endpoints are never cached.
- Icons are generated from `docs/branding/globewatch-icon.jpg` with `tools/generate-icons.sh` (macOS `sips`).
- Releasing: run `tools/bump-version.sh X.Y.Z` (updates `api/version.php` and the service-worker cache name),
  add the changelog entries, commit and tag `vX.Y.Z`.
- Serve `sw.js` and `manifest.webmanifest` with `Cache-Control: no-cache` (the bundled `.htaccess` does it;
  on nginx add the same).

## Requirements

PHP 8.1+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`, `simplexml` · MySQL 5.7+ or MariaDB 10.3+.
PHP-FPM or LiteSpeed is recommended (responses are sent before background refreshes run).

## Installation

1. Create an empty MySQL database.
2. Copy `api/config.php.example` to `api/config.php` and fill in the database credentials and
   `ADMIN_PANEL_PASSWORD`. `api/config.php` is git-ignored: never commit it.
3. Open `admin.php`, sign in and press **Install database**. Alternatively, import `sql/schema.sql`
   with phpMyAdmin. The installer is idempotent and never overwrites existing data.
4. Optional but recommended: add free keys to `config.php`. `HAPI_EMAIL` enables conflict intensity,
   unrest and violence against civilians. `FIRMS_MAP_KEY`, `UCDP_TOKEN` and `RELIEFWEB_APPNAME` enable more sources.
5. Optional: set `REFRESH_KEY` and have a free pinger (cron-job.org, UptimeRobot) call
   `https://your-site/api/refresh.php?key=…` every 5 minutes to keep every cache warm.

Check every source from **admin.php → Run diagnostics**.

## How data stays fresh

Every source has its own cache row in `globewatch_api_cache` with *stale-while-revalidate*. Fresh data is
served as is. Stale data is served immediately and refreshed **after** the response has been sent.
Slow sources (GDELT, HDX HAPI, FIRMS…) are always refreshed in the background, and the page polls again
while they warm up. A failed refresh never overwrites good data. MySQL advisory locks prevent duplicate
refreshes, and GDELT calls are serialized and paused after a rate-limit response.

Default refresh pace: USGS/EMSC 2 min · GDACS 5 min · EONET 10 min · ReliefWeb, FIRMS, GDELT 30 min ·
HDX HAPI 6 h · UCDP 12 h. Override it with `SOURCE_TTL_OVERRIDES`.

## Security

- Credentials live only in `api/config.php` (git-ignored). Upstream tokens are redacted from server logs.
- Admin: session regenerated at login, rate-limited login (5 attempts / 15 min), CSRF on every action,
  POST-only sign-out, diagnostics behind the admin session.
- Public errors never expose internal messages. External titles and links are escaped and restricted to http(s).

## Changelog

- **2.1.3** — Added the MIT license.
- **2.1.2** — The PWA icon replaces the generic globe in the header, admin panel and favicon.
- **2.1.1** — Fix: the map layers window no longer shrinks/jumps when toggling switches near the bottom of the list.
- **2.1.0** — Progressive Web App: web manifest, service worker (offline shell and last-known data), offline page,
  “Install app” menu entry, app icons generated from the official icon, home-screen shortcuts, `tab` deep link.
- **2.0.0** — Repository reorganised for deployment from the root. One definitive `sql/schema.sql` with
  one-click installation (migrations removed). Redesigned UI: glass side panel or bottom sheet, category
  tabs, intensity selector, refined markers and detail cards, map centred on the visible area. Front end
  split into cacheable CSS/JS. Indexed search, request timeouts, no fake demo data on errors. Redesigned
  admin panel with login rate limiting. Security: credentials removed from the template, log redaction,
  unused public `reliefweb.php` endpoint removed, kv cache housekeeping.
- **1.12.0** — Map layers panel, NWS/CAP alerts, USGS HANS, NGA ASAM, FAO locusts, NOAA SWPC,
  HAPI humanitarian context, alerts.in.ua, Cloudflare Radar, IDMC.
- **1.11.0** — 12 new sources (UCDP Candidate, WHO DON, IFRC GO, Copernicus EMS, EMSC, NHC, tsunami,
  Meteoalarm, IODA, GVP, ACAPS, INFORM Risk).
- **1.10.x** — English UI, per-source stale-while-revalidate cache, GDELT rate-limit fixes, HDX HAPI.
- Older versions: see `docs/HISTORY-v1.9.md`.

---

<a id="italiano"></a>
## Italiano

Mappa mondiale live di conflitti armati, disordini, violenza contro i civili, disastri naturali ed
emergenze umanitarie, basata solo su open data. PHP + MySQL, funziona su hosting condiviso senza cron.

**Installazione:** crea un database vuoto e copia `api/config.php.example` in `api/config.php`, poi
inserisci le credenziali del DB e `ADMIN_PANEL_PASSWORD`. Apri `admin.php` e premi **Installa database**
(oppure importa `sql/schema.sql`). Consigliato: aggiungi `HAPI_EMAIL` per l'intensità dei conflitti.
Facoltativo: imposta `REFRESH_KEY` e un pinger gratuito su `api/refresh.php?key=…` ogni 5 minuti.

`api/config.php` contiene password e chiavi: è escluso da git e non va mai condiviso.

## License

Released under the [MIT License](LICENSE).
