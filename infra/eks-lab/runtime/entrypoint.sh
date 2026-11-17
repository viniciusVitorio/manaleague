#!/bin/sh
set -eu
php "$(dirname "$0")/database-guard.php"
if [ -d /var/www/html/storage ]; then
    mkdir -p /var/www/html/storage/app/private /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/logs
fi
# No migration, seeding, import, or config cache at startup. Jobs own schema changes.
exec "$@"
