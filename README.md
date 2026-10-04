# Trifecta Solar Ops Tracker

Internal project, service and task tracker for Trifecta Solar. It replaces the Project Tracker and Service Tracker spreadsheets. Google Drive stays the file store; projects and tickets link to their Drive folders.

- **Stack:** PHP 8.3 (no framework), SQLite in WAL mode, Apache, and Docker. There is no JavaScript build step.
- **Portable:** the whole app is this folder plus one database file in `data/`.
- **Hosting:** runs anywhere Docker runs; see [Running it](#running-it).
- **Working on the code:** [CLAUDE.md](CLAUDE.md) has the rules (nothing person- or environment-specific in git, add-only imports, tabs, migrations, checks before pushing).

## Features

- **Sign-in:** email and password (Argon2id, 8+ characters with upper, lower, number and symbol). Logins are per device and never expire; users sign out their own devices, admins can sign out anyone. First-run setup page, admin user management, and My account (initials, password, devices).
- **Dashboard:** phase counts, my Tasks, "My open items" (project steps you own that are actionable now, grouped by project) and a 5-day forecast (Open-Meteo) for the location set in `.env`.
- **Projects:**
  - List with phase tabs (Active = Pre-Install, Installation and Closeout; Clear to install; On hold; Completed; Cancelled; All), salesperson filter and search. Completed and Cancelled fold earlier years under a header.
  - Create and edit, with customer and municipality added inline; module, inverter and battery lines with computed DC/AC kW, ratio and $/W.
  - Steps copied from an admin-editable template (task + sub-task, gates, reference # labels, default owners), edited inline (needed / target / done / ref # / owner / note), plus custom steps, duplicates and add-from-template.
  - Status: Installation when install starts, Closeout at PTO, Completed when closeout and payments are resolved. "Clear to install" once the building permit and interconnection approval are in.
- **Service:** tickets with site, coverage, owner, schedule, monitoring portal link and billing; visits with crew, trips and man-hours. Tabs for Open, Ready to invoice, Completed and All.
- **Tasks:** one-off to-dos assigned to a person, optionally linked to a project or service ticket, with due dates and comments.
- **Reports** (each prints on its own): Sales, Projection, Project time and Service.
- **Lists:** customers and contacts (anyone), third-party directory (admins), municipalities unique per county (anyone adds; admins set the usual zoning, plan review and inspection providers), task template (admins), users (admins).
- **Activity log:** every tracked field change, event and comment on projects, tickets, tasks and users.
- **About page:** app version (commit read from `.git`), hosting, server, HTTPS certificate and data counts.
- **Operations:** verified backup command, health endpoint (`/health`), installable PWA (needs HTTPS).

## Layout

```
bin/console          CLI (see Console commands below)
docker/              Apache vhost, php.ini, entrypoint
migrations/          NNN_name.sql, applied in order, once each
public/              web root (index.php front controller, assets, manifest, sw.js)
src/                 App classes (Auth, Db, Activity, Tasks, Projects, ...) and Controllers/
tools/import/        Python builders that turn the old spreadsheets into import files
views/               PHP templates
data/  backups/      runtime only, git-ignored
import/              import files and per-project data (customer data), git-ignored
```

## Running it

```sh
git clone https://github.com/cecil-t/trifecta-solar.git && cd trifecta-solar
docker compose up -d --build
```

Then open http://localhost:8089 (change the port in `docker-compose.yml`). The first visit opens the setup page, which creates the first admin; add everyone else under **Users**.

- **Data:** the SQLite database lives in the Docker volume `trifecta-data`. Never copy it while the app runs; use the backup command instead.
- **Backups:** `docker exec -u root trifecta-solar php bin/console backup` writes a verified snapshot to `backups/` in the repo folder and keeps the newest 30 (`--keep=N`). Schedule it and copy `backups/` offsite however your host does that.
- **Restore:** stop the container, copy a snapshot over `trifecta.sqlite` in the `trifecta-data` volume (delete any `-wal` / `-shm` files next to it, owner `www-data`), start it again.
- **Updating:** `git pull` in the repo folder. The code is mounted into the container, so it is live at once, and new migrations apply on the next page load. Rebuild (`docker compose up -d --build`) only when `Dockerfile`, `docker-compose.yml` or `docker/` change.
- **Settings:** optional, in `.env` in the repo folder; see `.env.example`. Behind a reverse proxy that handles HTTPS, set `TRUST_PROXY=true`. The dashboard weather needs `WEATHER_PLACE`, `WEATHER_LAT` and `WEATHER_LON`.
- **Phones:** the app installs to a home screen (PWA) when served over HTTPS.
- **Health check:** `GET /health` returns `{"status":"ok",...}`.

## Console commands

```
migrate                                   Apply pending database migrations
backup [--keep=N]                         Snapshot the database into backups/ (verified), prune to N
user:list                                 List users
user:password <email>                     Set a user's password (prompts, input hidden)
import:projects <file.json> [--dry-run]   Add projects (skips project numbers that exist)
import:service <file.json> [--dry-run]    Add service tickets (never changes existing ones)
import:todos <file.json> [--dry-run]      Add Tasks (skips titles already open)
fix:payment-labels [--dry-run]            Name payment milestones the first import left blank
fix:completed-payments [--dry-run]        Mark open payments received on Completed projects
fix:question-defaults [--dry-run]         Fill blank SolarEdge warranty / rebate / VNM answers with No where known
```

In the container: `docker exec -u root trifecta-solar php bin/console <command>`. Always run `--dry-run` first.
`import:projects` also has `--replace`, which deletes and re-imports projects; it is not used now that live data is edited by hand.

## Rules of the road

- **Never edit a migration that has already been applied anywhere.** Add a new numbered file instead.
- Every save path logs its field changes through `Activity::changes()`. That is the audit trail.
- All timestamps are stored in UTC (ISO-8601) and displayed in Eastern time. Calendar dates are stored as `YYYY-MM-DD` and always displayed with the year.
- Nothing person- or environment-specific in git (the repo is public). Import files, contracts, spreadsheets, backups, `import/overrides.json` and `.env` stay out of the repo.

## Local development (without Docker)

```sh
php bin/console migrate
php -S localhost:8089 -t public public/index.php
```

Then open http://localhost:8089. `DB_PATH=/path/to/copy.sqlite` points it at a copy of a backup.
