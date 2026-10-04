# Working on the Trifecta Solar Ops Tracker

Read README.md for the feature list and layout. This file covers how to work in the repo.

## Hard rules

- **No customer data in git, ever.** That covers import.json, service.json, contracts, the tracker .xlsx, Drive listings and DB backups. `/import/`, `/data/` and `/backups/` are gitignored. Keep those files in the session scratchpad, and hand them to Greg through file delivery, never a commit.
- **Live data is edited by hand now.** Imports and fix scripts are add-only or fill-blanks-only. Never run or suggest `import:projects --replace`. Only overwrite a field when Greg names it.
- **No em dashes** anywhere: UI text, comments, commit messages, replies.
- Every data-changing console command gets a `--dry-run`, and Greg runs the dry run before the real one.

## Code conventions

- PHP 8.3 with no framework. `src/` holds classes (namespace `App\`) and `src/Controllers/`. Views are plain PHP in `views/`, rendered through `View::render`, and `HtmlIndent::tidy` re-indents the output.
- Views are indented with **tabs**. PHP, CSS and JS still use 4 spaces (Greg has not decided whether to convert them).
- Schema changes go in a new numbered file in `migrations/` (next is 012). Migrations apply automatically on start and on each request.
- All writes go through `Activity::changes` / `Activity::event` so the project or ticket log records them.
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

Push to `main`. The NAS runs `git pull` hourly from 08:00 to 16:00 as root in `/volume1/docker/trifecta-solar`. Migrations apply on the next request. Console commands run as `docker exec -u root trifecta-solar php bin/console <command>`.
