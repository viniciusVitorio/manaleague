#!/bin/sh
set -eu

database_path="${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}"

mkdir -p "$(dirname "$database_path")" \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

touch "$database_path"

php artisan migrate --force --no-interaction

exec "$@"
