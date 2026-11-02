#!/bin/sh
set -eu

mkdir -p storage/app \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ "${DB_CONNECTION:-pgsql}" = "sqlite" ]; then
    database_path="${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}"
    mkdir -p "$(dirname "$database_path")"
    touch "$database_path"
fi

php artisan migrate --force --no-interaction

# Migracao unica SQLite -> PostgreSQL: se o banco antigo ainda estiver no volume, os dados dele
# sao copiados (so quando o Postgres ainda nao tem usuarios) e o arquivo e renomeado para nunca
# mais ser importado. Se a copia falhar, o container nao sobe e o arquivo fica intacto.
legacy_sqlite=/var/www/html/storage/app/database.sqlite
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ] && [ -s "$legacy_sqlite" ]; then
    php artisan db:import-sqlite "$legacy_sqlite" --if-empty --no-interaction
    stamp="$(date +%Y%m%d%H%M%S)"
    for suffix in "" -wal -shm; do
        if [ -e "$legacy_sqlite$suffix" ]; then mv "$legacy_sqlite$suffix" "$legacy_sqlite.migrado-$stamp$suffix"; fi
    done
fi

exec "$@"
