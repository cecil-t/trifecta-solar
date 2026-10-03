# Trifecta Solar Tracker

Internal project and service tracker for Trifecta Solar. It replaces the Project Tracker and Service Tracker spreadsheets.

- **Stack:** PHP 8.3 (no framework), SQLite in WAL mode, Apache, and Docker. There is no JavaScript build step.
- **Portable:** the whole app is this folder plus one database file in `data/`.
- **Hosting:** runs anywhere Docker runs. For setup on the DS225+, see [docs/synology-setup.md](docs/synology-setup.md).

## What's here so far

- Email/password login using Argon2id hashes and the password policy (8+ characters, upper, lower, number, symbol)
- Persistent per-device logins that never expire. Users can sign out individual devices, and admins can sign out everyone.
- First-run setup page, admin user management, and My account
- Unified activity log (`activity_log`): comments, plus automatic change and event entries for every tracked field
- Lookup tables: PA/MD/DE counties, utilities, and funding sources
- **Projects:** list with phase tabs and filters, create/edit (customer and municipality can be added inline),
  module/inverter/battery lines with computed DC/AC kW, ratio and $/W, and a running project log with comments
- **Tasks:** admin-editable template (task + sub-task, gates, reference # labels, default owners) copied into each
  new project; per-project tasks are editable inline (needed / target / done / ref # / owner / note), with custom
  tasks, duplicates (e.g. a second utility upgrade), and add-from-template
- **Status:** Installation when install starts, Closeout at PTO, Complete when closeout and payments are resolved;
  "Clear to install" when the building permit and interconnection approval (conditional or full) are in
- Customers and contacts (anyone), third-party directory (admins), municipalities unique per county (anyone adds,
  admins set usual zoning / plan review / inspection providers)
- Dashboard with phase counts and "My open items" (everything assigned to you that is still open)
- Verified nightly backup command, health endpoint, and installable PWA shell (the PWA needs HTTPS)

## Layout

```
bin/console          CLI: migrate | backup | user:list | user:password <email>
docker/              Apache vhost, php.ini, entrypoint
migrations/          NNN_name.sql, applied in order, once each
public/              web root (index.php front controller, assets, manifest, sw.js)
src/                 App classes (Auth, Db, Activity, Router, ...) and Controllers/
views/               PHP templates
data/  backups/      runtime only, git-ignored
```

## Rules of the road

- **Never edit a migration that has already been applied anywhere.** Add a new numbered file instead.
- Every save path logs its field changes through `Activity::changes()`. That is the audit trail.
- All timestamps are stored in UTC (ISO-8601) and displayed in Eastern time. Calendar dates are stored as `YYYY-MM-DD`
  and always displayed with the year.

## Local development (without Docker)

```sh
php bin/console migrate
php -S localhost:8089 -t public public/index.php
```

Then open http://localhost:8089.
