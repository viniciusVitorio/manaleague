#!/bin/sh
set -eu
cd "$(dirname "$0")"
compose() { docker compose --env-file .env.local -f compose.yaml "$@"; }
reject() {
    status=0
    compose exec -T app env "$@" php infra/eks-lab/runtime/seed.php >/dev/null 2>&1 || status=$?
    [ "$status" = 64 ] || { echo "FAIL: seed safety guard returned $status instead of 64"; exit 1; }
}
reject LAB_DATABASE_MODE=
reject LAB_DATABASE_MODE=unknown
reject DB_SSLMODE=prefer
reject LAB_DATABASE_MODE=rds DB_HOST=production.fixture.us-east-1.rds.amazonaws.com DB_SSLMODE=verify-full
reject LAB_DATABASE_MODE=rds DB_HOST=manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com AWS_DEFAULT_REGION=us-east-1 DB_SSLMODE=require
reject APP_ENV=production
reject DB_HOST=not-lab
reject DB_DATABASE=not_lab
reject DEMO_PASSWORD=
# Safe loopback dead port: never contact an actual production endpoint while testing a refusal.
reject DB_URL=pgsql://fixture:fixture@127.0.0.1:9/unsafe DEMO_PASSWORD=twenty-random-characters-for-test
reject DB_CONNECTION=sqlite
echo 'PASS: standalone seeder rejects missing/unknown mode, TLS downgrade, unsafe RDS host, environment, DB host/name/URL/driver and absent password'
