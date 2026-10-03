#!/bin/sh
set -e

APP=/var/www/app

# data/ (SQLite) and backups/ must be writable by Apache's user (www-data).
mkdir -p "$APP/data" "$APP/backups"
chown -R www-data:www-data "$APP/data" "$APP/backups"

# Apply any pending database migrations before serving traffic.
su -s /bin/sh www-data -c "php $APP/bin/console migrate"

exec docker-php-entrypoint "$@"
