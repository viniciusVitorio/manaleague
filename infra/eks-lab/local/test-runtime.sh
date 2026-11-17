#!/bin/sh
set -eu
cd "$(dirname "$0")/../../.."
export LAB_DATABASE_MODE=local DB_SSLMODE=disable
entry=infra/eks-lab/runtime/entrypoint.sh
if [ ! -f "$entry" ]; then echo 'FAIL: lab entrypoint missing'; exit 1; fi
reject() {
  if env DB_CONNECTION=pgsql APP_ENV="$1" DB_HOST="$2" DB_DATABASE="$3" APP_KEY="$4" sh "$entry" /bin/true >/dev/null 2>&1; then
    echo "FAIL: unsafe runtime accepted: $1 $2 $3"; exit 1
  fi
}
reject production postgres manaleague_eks_lab test
reject eks-lab production-db manaleague_eks_lab test
reject eks-lab postgres manaleague test
reject eks-lab postgres manaleague_eks_lab ''
if env DB_CONNECTION=pgsql APP_ENV=eks-lab DB_HOST=postgres DB_DATABASE=manaleague_eks_lab APP_KEY=test DB_URL=pgsql://unsafe/production sh "$entry" /bin/true >/dev/null 2>&1; then
  echo 'FAIL: DB_URL overrides the lab guard'; exit 1
fi
if env APP_ENV=eks-lab DB_HOST=postgres DB_DATABASE=manaleague_eks_lab APP_KEY=test DB_CONNECTION=sqlite sh "$entry" /bin/true >/dev/null 2>&1; then
  echo 'FAIL: non-PostgreSQL connection accepted'; exit 1
fi
env APP_ENV=eks-lab DB_HOST=postgres DB_DATABASE=manaleague_eks_lab APP_KEY=test DB_CONNECTION=pgsql sh "$entry" /bin/true
echo 'PASS: runtime rejects non-lab environment/database and missing key'
