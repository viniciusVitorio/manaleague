#!/bin/sh
set -eu
cd "$(dirname "$0")"
if [ ! -f .env.local ]; then
    umask 077
    { printf 'APP_KEY=base64:'; openssl rand -base64 32; printf 'DB_PASSWORD='; openssl rand -hex 32; printf 'DEMO_PASSWORD='; openssl rand -hex 24; } > .env.local
fi
compose() { docker compose --env-file .env.local -f compose.yaml "$@"; }
compose up -d --wait postgres
compose run --rm migrate
compose run --rm seed
compose up -d app app2 web web2 reverb edge
# No credentials are printed. The disposable demo password stays in .env.local.
echo 'Disposable lab: http://localhost:18080; demo@manaleague.test; password in local/.env.local'
