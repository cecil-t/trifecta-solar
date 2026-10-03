#!/bin/sh
set -e

APP=/var/www/app

# data/ is a Docker volume holding the SQLite database; Apache's user (www-data) must own it.
mkdir -p "$APP/data"
chown -R www-data:www-data "$APP/data"
chmod 775 "$APP/data"

# backups/ is on the NAS share; snapshots are written by root via `docker exec`, so just ensure it exists.
mkdir -p "$APP/backups" 2>/dev/null || true

# Apply pending database migrations. If this fails, still start Apache so the container
# stays up (no restart loop) and the error is visible in the logs and the browser.
if ! su -s /bin/sh www-data -c "php $APP/bin/console migrate"; then
    echo "WARNING: database migration failed; see the error above. Starting web server anyway." >&2
fi

exec docker-php-entrypoint "$@"
