# Trifecta Solar Ops Tracker

Internal project, service and task tracker for Trifecta Solar. It replaces the Project Tracker and Service Tracker spreadsheets. Google Drive stays the file store; projects and tickets link to their Drive folders.

- **Stack:** PHP 8.3 (no framework), SQLite in WAL mode, Apache, and Docker. There is no JavaScript build step.
- **Portable:** the whole app is this folder plus one database file in `data/`.
- **Hosting:** runs anywhere Docker runs; see [Running it](#running-it).
- **Working on the code:** [CLAUDE.md](CLAUDE.md) has the rules (nothing person- or environment-specific in git, add-only imports, tabs, migrations, checks before pushing).

## Features

- **Sign-in:** email and password (Argon2id, 8+ characters with upper, lower, number and symbol). Logins are per device and never expire; users sign out their own devices, admins can sign out anyone. First-run setup page, admin user management, and My account (initials, password, devices).
- **Dashboard:** phase counts, my Tasks, "My open items" (project steps you own that are actionable now, grouped by project) and a 5-day forecast (Open-Meteo) for the location set in `.env`.
- **Search:** a box in the top bar finds projects and service tickets together (names, customer, numbers, municipality, ticket problem and address). One match opens it directly; back links return to the results.
- **Sales** (draft, replacing the Master Buildsheet spreadsheet; code and URLs say presales):
  - Opportunities (a customer and a site being quoted): Open, Sold (names the build sheet that sold) or Lost (with an optional reason), with a log. Each has a lead source (self-generated, referral with the referrer searched from the directory or typed, Trifecta lead with how it came in, repeat customer) and an optional in-house handoff from another salesperson.
  - Commission plan (Rates page): commission and self-generated lead bonus tiers, tax-bracket style; a flat referral bonus; the in-house handoff share. Every new build sheet copies the plan; build sheets from before the plan keep the old single-rate math.
  - Any number of build sheets per opportunity, copied to try other designs. A build sheet keeps the prices it was built with; lines whose catalog price changed since, or whose catalog price is older than the stale setting, are flagged, and "Copy at today's prices" makes a repriced copy while the original stays.
  - Build sheet editor: arrays (roof, ground, carport, pole, tracker; tilt drawn, red when steep), catalog lines by category with automatic per-watt and per-module quantities, extended warranty switch, a price override on any catalog line (pencil beside the unit price; needs a reason; boxed in yellow and logged), markup in 0.5% steps that can't go below the floor on the Rates page, created and updated dates, custom lines, labor priced per watt or total (with man-hours), extras without markup, contingency rules with a cap, a minimum and an override that needs a reason, tax exempt (major equipment only; BOS always taxed), deposit by class and size, payment schedule, warranty summary to copy, and cost, retail, margin and $/W figures that update as you type (`App\Pricing`). Commission is solved into the price exactly on the price less pass-through items and extras (`App\Commission`); the self-generated (net-new customers only) or referral bonus comes out of margin; the salesperson's total and any handoff split show beside the margin.
  - Trench builder on the build sheet (`App\Trench`): trench segments (open trench, linear bore or customer trench, each drawn to scale) and the AC, DC and comms circuits that run through them. Wire is sized for ampacity and voltage drop and conduit for fill from NEC tables (aluminum past a size set on the Rates page), then priced from the "Trench builder" parts in the catalog: mobilization once per job (trench inspections are in permitting), digging or boring per foot, conduit, wire per conductor foot, pull labor and terminations. A build sheet keeps the trench settings and part prices it was priced with.
  - Savings (`App\Savings`) on every build sheet, from the production estimate, the opportunity's OpenEI rate and use, and the price: Pennsylvania net metering month by month (customer charge always billed, excess banked as kWh credit, credit left at May 31 paid at the Price to Compare), SRECs, degradation and rate escalation over the analysis period, payback, discounted payback, net present value, total return and IRR. Assumptions default from the Rates page and can change per build sheet; incentives (rebates, grants, tax credits) per build sheet. Monthly use is the bill's 12 months, or the yearly use spread by a typical monthly profile (NREL End-Use Load Profiles: residential non-electric heat, residential electric heat, small commercial).
  - Site layout (`/presales/{id}/designs/{designId}/layout`, desktop): arrays of panels drawn on Google satellite tiles (Map Tiles API, passed through the server and never stored). Click two points along the eave and one up the roof and the box fills with panels at the module's size (Length and Width on catalog modules), with the panel and row gaps from the Rates page, shortened up the slope by the pitch as seen from above. Select, move, turn, duplicate, add or remove panels, undo. Roof, ground and tracker arrays; trackers are drawn flat with a dashed red circle for the space they sweep. A measuring tool reads along the selected array's slope, rounded down to the inch. Roof data asks the Solar API for the roof faces of the building in the middle of the map and fills in the pitch of arrays drawn on them. Saving updates the build sheet: drawn arrays (read-only there except roof material and shading), the module count when there is one module line, and the price. The proposal's System Summary shows the layout on the satellite view.
  - Customer proposal (`/presales/{id}/designs/{designId}/proposal`, and `/proposal/pdf` through WeasyPrint): cover with cover letter, System Summary, System Performance (both production models, monthly chart, arrays with compass and pitch drawings, assumptions), Electric Bill Savings, Return on Investment, the 30-year projection, Price and Payments (incentives, an optional financing estimate, the payment schedule), Next Steps, Clarifications and the Terms & Conditions with signature lines. A build sheet gets a quote number (year and 4 digits) the first time its proposal opens; valid-until defaults from the Rates page and a salesperson can set it up to a limit; one customer notes field; an admin-only production guarantee (a percent of the PVWatts estimate). The Clarifications and terms are versioned blocks with conditions (in-house or SolarInsure warranty, guarantee, battery, residential or not) on Sales > Proposal terms (admins edit; `bin/console import:terms` adds the first set).
  - Who sees what: a salesperson (Users: Sales) sees only the opportunities they are the salesperson on or created; admins see all and reassign the salesperson; other users don't see Sales. Everyone can view the catalog; admins and salespeople edit it; only admins edit the buildsheet rates.
  - Catalog (Lists): internal cost per item with shipping, per-line adder, minimum, tax class, specs, warranty, price date and source; stale prices flagged; Confirm price dates a checked price. An item can be pass-through (added after markup, no commission, no tax) and can pay the salesperson (site visit, REAP grant paperwork). Admins and users marked Sales edit it.
  - Sales commissions (Lists, and the Sales page): one record per sold job, set up from the project (filled from its sold opportunity and build sheet), with what each person is owed after any handoff split, payments and adjustments with a reason, referral bonuses to pay and W-9s. Closing the project's "Pay sales commission" closeout step (or Mark paid) pays every balance; reopening the step takes it back. Admins manage; salespeople see their own.
- **Projects:**
  - List with phase tabs (Active = Pre-Install, Installation and Closeout; Clear to install; On hold; Completed; Cancelled; All), salesperson filter (remembered per user) and search. Completed and Cancelled fold earlier years under a header.
  - Create and edit, with customer and municipality added inline; module, inverter and battery lines with computed DC/AC kW, ratio and $/W, plus how the inverter gets internet.
  - Township and county lookup from the site address (US Census geocoder; OpenStreetMap finds the point when the Census can't match a rural address). New project fills Municipality as the address is typed and the user can override it. Edit runs a one-time check of a saved address and flags a mismatch until someone picks the lookup's municipality or keeps theirs. Lookups are cached by address.
  - Roof faces for rooftop installs (name, material, azimuth, tilt in degrees or pitch, panels per face), shown as Roof details under System with a warning when the faces don't add up to the panel count.
  - Project page: Notes (one free-text block, saved with who and when; a save never overwrites someone else's newer save), Tasks and the latest comment side by side.
  - Print summary (`/projects/{id}/summary`): a printable sheet for the designer at kickoff with customer and contacts, site and jurisdiction, system, contract basics, equipment, roof faces and Notes, but no phases, steps or dates. The contract price shows unless "Hide contract price" is clicked.
  - Steps copied from an admin-editable template (task + sub-task, gates, reference # labels, default owners), edited inline (needed / target / done / ref # / owner / note), plus custom steps, duplicates and add-from-template.
  - Status: Installation when install starts, Closeout at PTO, Completed when closeout and payments are resolved. "Clear to install" once the building permit and interconnection approval are in.
- **Service:** tickets with site, coverage, owner, schedule, monitoring portal link and billing; visits with crew, trips and man-hours. Tabs for Open, Ready to invoice, Completed and All.
- **Tasks:** one-off to-dos assigned to a person, optionally linked to a project or service ticket, with due dates and comments.
- **Reports** (each prints on its own): Sales, Projection, Project time, Service and Opportunities. Opportunities shows the sales pipeline by salesperson (an opportunity counts once: its sold build sheet, otherwise the average of its build sheets), every contingency and line price override with its reason, opportunities going cold (no edits, build sheet saves or log notes in 30 days) and lost reasons. Salespeople see only their own.
- **Monitor:** every inverter brand's systems on one page (SolarEdge and APsystems APIs; Enphase, Growatt and SMA portal sign-ins): fleet tiles, a table per manufacturer, a 13-month production chart and an Issues list with an Open ticket button that starts a service ticket filled in from the system (alert, size, portal link, and the project or customer it matches). The `monitor:poll` collector (cron, every 15 minutes) stores the data; the page only reads it. Display links (admins, under Lists) open the page on an office screen without the menus and without signing in.
- **Lists:** customers and contacts (anyone), third-party directory (admins), municipalities unique per county (anyone adds; admins set the usual zoning, plan review and inspection providers), task template (admins), users (admins), Monitor APIs and display links (admins), Proposal APIs (admins: a test for each service the proposal system will use: Google geocoding, Solar API roof faces and an aerial image for an address; NSRDB typical-year weather for a point, cached per grid cell in `data/solar/`, with SAM's example system run on it; OpenEI utility rates at a point with SAM's bill for one; a rate history check (every OpenEI version of a place's rates billed by SAM at the same use, with the 3 and 5 year yearly change and any sell rate); a check that SAM, the System Advisor Model built into the Docker image, is installed and runs, also `bin/console sam:check`; and a test PDF from WeasyPrint, also in the image), and Reference jobs under it (admins: built, monitored systems with their signed proposal's estimate, run through SAM's detailed model and PVWatts v8 on NSRDB weather and compared month by month with the proposal and monitored production; added with `bin/console import:reference-jobs`).
- **Activity log:** every tracked field change, event and comment on projects, tickets, tasks and users.
- **About page:** app version (commit read from `.git`), hosting, server, HTTPS certificate and data counts.
- **Operations:** verified backup command, health endpoint (`/health`), installable PWA (needs HTTPS).

## Layout

```
bin/console          CLI (see Console commands below)
docker/              Apache vhost, php.ini, entrypoint
migrations/          NNN_name.sql, applied in order, once each
public/              web root (index.php front controller, assets, sw.js; the manifest is served by ManifestController)
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
import:updates <file.json> [--dry-run]    Fill blank fields on existing projects (never overwrites)
import:presales <file.json> [--dry-run]   Add Sales catalog items and buildsheet rates (never changes existing ones)
import:terms <file.json> [--new-version] [--dry-run]
                                          Add the proposal terms as a version (only when there are none, unless --new-version)
import:commissions <file.json> [--paid-on=YYYY-MM-DD] [--paid-before=YYYY-MM-DD] [--dry-run]
                                          Add sold jobs from the commission spreadsheets (tools/import/build_commissions.py).
                                          Jobs marked paid (green on the sheet), and with --paid-before those signed before
                                          that date, get a payment for what is owed if none yet, dated --paid-on (default today)
fix:payment-labels [--dry-run]            Name payment milestones the first import left blank
fix:completed-payments [--dry-run]        Mark open payments received on Completed projects
fix:question-defaults [--dry-run]         Fill blank SolarEdge warranty / rebate / VNM answers with No where known
geo:check [--dry-run]                     Township / county lookup for every project address not yet checked; lists mismatches
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
