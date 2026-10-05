# Working on the Trifecta Solar Ops Tracker

Read README.md for the feature list and layout. This file covers how to work in the repo.

## Hard rules

- **The repo is public. Nothing person-specific or environment-specific goes into git, ever:** not in code, migrations, comments, UI text, docs or commit messages.
  - Customer data: names, addresses, phones, emails, contract prices, import.json, service.json, contracts, the tracker .xlsx, Drive listings, DB backups.
  - Staff data: names, emails, initials tied to a person, crew lists. Accounts are created through /setup and Users.
  - Environment data: live hostnames and ports, IP addresses, personal domains, credentials, anything from the live `.env`.
  - Where it goes instead: gitignored files (`/import/`, `/data/`, `/backups/`, `.env`). Per-project and staff data for `tools/import/` is in `import/overrides.json`, which Greg keeps. Hand such files to Greg through file delivery from the session scratchpad, never a commit.
  - Examples in comments, placeholders and docs use made-up names and example.com.
  - Allowed: Greg's name in the About credits, the company name, generic setup defaults (Docker port 8089, container name), and the repo URL.
  - Customer and staff data were scrubbed from all history on 2026-10-04 with two `git filter-repo` rewrites. Check every commit before pushing; a slip means another rewrite and a reset of the live clone.
- **Live data is edited by hand now.** Imports and fix scripts are add-only or fill-blanks-only. Never run or suggest `import:projects --replace`. Only overwrite a field when Greg names it.
- **Data changes are import files, not code.** A one-off change to live data ("set X on these projects") is a JSON file for `import:updates` (fill-blanks only) or another import, built in the session scratchpad and handed to Greg. Don't add a console command or code path for a one-off data fix. If the import can't express the change yet, extend the import generically.
- **No em dashes** anywhere: UI text, comments, commit messages, replies.
- Every data-changing console command gets a `--dry-run`, and Greg runs the dry run before the real one.
- Record-only imports (`build_import.py --older`) add history projects with only their dated steps and no payments. They skip existing project numbers and names and refuse `--replace`.
- Project numbers are text. Early jobs keep two digits (01 to 12, 20) and fold under their signed year on the Completed tab.

## Code conventions

- PHP 8.3 with no framework. `src/` holds classes (namespace `App\`) and `src/Controllers/`. Views are plain PHP in `views/`, rendered through `View::render`, and `HtmlIndent::tidy` re-indents the output.
- All code is indented with **tabs** (PHP, views, CSS, JS, SQL, Python, config); `.editorconfig` sets it. Only YAML and Markdown use spaces. A 1 to 3 space remainder after the tabs is fine for docblock ` *` lines and SQL alignment.
- Schema changes go in a new numbered file in `migrations/` (next is 014). Migrations apply automatically on start and on each request.
- All writes go through `Activity::changes` / `Activity::event` so the project or ticket log records them.
- Security:
  - Escape every value in views with `e()` (or cast to int/float). User links render only through `safe_url()`. JS never uses `innerHTML` with data.
  - A Content Security Policy (sent from `public/index.php`) allows scripts and styles only from this site, with no inline script. So no `<script>` blocks, no `onclick=`/`onsubmit=`/`onchange=` attributes and no `style=` attributes in views. Use the data attributes `app.js` handles (`data-href` rows, `data-confirm` forms and buttons, `data-autosubmit` selects, `data-print`, `data-toggle-next-row`, `.year-toggle`) or a script file in `public/assets/js/`.
  - Validate input on the server in each controller's `input()`; SQL uses `?` placeholders only; every POST carries `Csrf::field()`.
  - Return paths from `back` parameters go through `local_path()`.
- UI wording: "Completed" (never "Done" or "Archived"), "Tasks" for to-dos, and "Open items" for project steps on the dashboard.
- Search on Projects, Service and Tasks always switches to the All tab.
- Open items rules live in `Tasks::openItemsFor`: phase reached, target date within 30 days, or next payment. Then:
  - R1 hides earlier-phase leftovers.
  - R2 treats a step as done when a later step of its task is done (`Tasks::IMPLIED_BY`).
  - R4 collapses grouped tasks (`Tasks::GROUPED`).
  - R3 (show only the next sub-step of sequential tasks) was proposed and is on hold.

## Checking work

- Run a local server with `php -S 127.0.0.1:8089 -t public public/index.php` (dev DB: `data/trifecta.sqlite`). Use `DB_PATH=/path/to.sqlite` to point at a copy of a live backup. Kill the server by its PID, not with pkill.
- Lint every changed PHP file with `php -l`.
- Validate rendered pages with the Nu HTML checker (`vnu-jar`). Greg runs W3C validation and expects it clean.
- Screenshot with Playwright at desktop (1300px) and phone (390px) widths. Chromium is at `/opt/pw-browsers/chromium`.

## Deploying

Push to `main`. The live server pulls `main` on a schedule, and migrations apply on the next request. Console commands run as `docker exec -u root trifecta-solar php bin/console <command>`. Where and how it is hosted is kept out of the repo (see the project context doc, `claude/ops-tracker-context.md`, in the claude.ai Project).
