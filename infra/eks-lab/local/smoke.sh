#!/bin/sh
set -eu
cd "$(dirname "$0")"
compose() { docker compose --env-file .env.local -f compose.yaml "$@"; }
sql() { compose exec -T postgres sh -c 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -U manaleague_lab -d manaleague_eks_lab -Atc "$1"' sh "$1"; }
compose exec -T app sh -c 'test ! -e .env && test ! -e .git && test ! -e infra/eks-lab/local/.env.local'
compose exec -T app sh infra/eks-lab/local/test-health.sh
compose exec -T app sh infra/eks-lab/local/test-runtime.sh
compose exec -T app sh infra/eks-lab/local/test-rds-guards.sh
compose exec -T reverb php -r 'exit(@fsockopen("app",9000) ? 1 : 0);'
echo 'PASS: PHP-FPM reachable only on the same Pod/container network namespace'
# Assertions run before seeding and after retry, without printing passwords.
compose run --rm seed
users=$(sql 'select count(*) from users')
[ "$users" = 7 ] || { echo "FAIL: expected 7 synthetic users, got $users"; exit 1; }
first=$(sql 'select count(*) from tournaments')
compose run --rm seed
second=$(sql 'select count(*) from tournaments')
[ "$first" = "$second" ] || { echo 'FAIL: seed duplicated tournaments'; exit 1; }
echo 'PASS: seed transaction creates seven synthetic accounts and retry is idempotent'
APP1_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$(compose ps -q app)")
APP2_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$(compose ps -q app2)")
export APP1_IP APP2_IP
sh test-seed-guards.sh
python3 smoke.py
spoof=$(compose logs --no-color web web2 | grep 'eks-lab-spoof-test')
[ -n "$spoof" ]
if printf '%s' "$spoof" | grep -Eq '203\.0\.113\.222|198\.51\.100\.222'; then
    echo 'FAIL: caller-controlled real IP was trusted'; exit 1
fi
echo 'PASS: spoofed X-Real-IP and XFF are ignored; appended proxy client IP logged'
verified=$(sql "select count(*) from users where email like '%@manaleague.test' and email_verified_at is not null")
[ "$verified" = 7 ] || { echo 'FAIL: synthetic users not verified'; exit 1; }
compose exec -T app php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach(App\Models\User::all() as $u) { if(Illuminate\Support\Facades\Hash::check("manaleague123",$u->password)) exit(1); }'
echo 'PASS: all demo users verified; no upstream fixed public password survives'
# A real database outage must remove readiness, not kill PHP liveness.
trap 'compose start postgres >/dev/null 2>&1 || true' EXIT INT TERM
compose stop postgres
[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 http://127.0.0.1:18080/readyz)" = 503 ]
[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 http://127.0.0.1:18080/up)" = 200 ]
compose up -d --wait postgres
trap - EXIT INT TERM
echo 'PASS: real PostgreSQL outage -> readiness 503 while PHP liveness stays 200; DB restored'
