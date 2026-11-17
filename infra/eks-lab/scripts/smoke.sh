#!/usr/bin/env bash
# LOCAL DOCKER ONLY. Uses disposable lab compose volumes, never AWS or kubectl.
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
umask 077
work="$(mktemp -d "${TMPDIR:-/tmp}/eks-lab-smoke.XXXXXX")"
cp "$root/infra/eks-lab/local/"{compose.yaml,edge.conf,smoke.sh,smoke.py,test-seed-guards.sh} "$work/"
# Segredos ficam 0600; apenas a configuracao nao secreta montada no nginx UID 101 e publica.
chmod 0644 "$work/edge.conf"
cd "$work"
project="manaleague-eks-lab-smoke-${GITHUB_RUN_ID:-$$}"
export COMPOSE_PROJECT_NAME="$project"
printf 'APP_KEY=base64:%s\nDB_PASSWORD=%s\nDEMO_PASSWORD=%s\n' "$(openssl rand -base64 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" > .env.local
compose() { docker compose -p "$project" --env-file .env.local -f compose.yaml "$@"; }
cleanup() { compose logs --no-color --tail=80 || true; compose down --volumes --remove-orphans; rm -rf -- "$work"; }
trap cleanup EXIT
# Existing tested images only; this stage cannot rebuild or replace app/web.
compose up -d --no-build --wait --wait-timeout 180 postgres
compose run --rm --no-deps migrate
compose run --rm --no-deps seed
compose up -d --no-build app app2 web web2 reverb edge
for attempt in {1..60}; do
  if curl --fail --silent http://127.0.0.1:18080/readyz >/dev/null; then break; fi
  sleep 2
done
curl --fail --silent http://127.0.0.1:18080/readyz >/dev/null
# Real HTTP/WAF, cross-replica DB sessions, WebSocket, repeatable demo seeding,
# and PostgreSQL-outage readiness assertions from the image/runtime smoke suite.
bash ./smoke.sh
printf '%s\n' 'PASS: exact app/web images passed the integration smoke suite.'
