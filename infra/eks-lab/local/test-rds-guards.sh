#!/bin/sh
set -eu
cd "$(dirname "$0")/../../.."
entry=infra/eks-lab/runtime/entrypoint.sh
run() {
  env APP_ENV=eks-lab DB_CONNECTION=pgsql DB_URL= DB_DATABASE=manaleague_eks_lab DB_USERNAME=manaleague_lab DB_PORT=5432 APP_KEY=test \
    LAB_DATABASE_MODE=local DB_HOST=postgres DB_SSLMODE=disable PGSSLROOTCERT= AWS_DEFAULT_REGION=us-east-1 \
    "$@" sh "$entry" /bin/true
}
count=0
reject() {
  status=0
  run "$@" >/dev/null 2>&1 || status=$?
  [ "$status" = 64 ] || { echo "FAIL: runtime safety guard returned $status instead of 64: $*"; exit 1; }
  count=$((count + 1))
}
reject LAB_DATABASE_MODE=
reject LAB_DATABASE_MODE=unknown
reject APP_ENV=production
reject DB_HOST=production-db
reject DB_DATABASE=manaleague
reject APP_KEY=
reject DB_URL=0
reject DB_URL=pgsql://fixture:fixture@127.0.0.1:9/unsafe
reject DB_CONNECTION=sqlite
reject DB_SSLMODE=prefer
reject LAB_DATABASE_MODE=local DB_HOST=manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com
reject LAB_DATABASE_MODE=rds DB_HOST=postgres DB_SSLMODE=verify-full
reject LAB_DATABASE_MODE=rds DB_HOST=127.0.0.1 DB_SSLMODE=verify-full
ca=/var/www/html/infra/eks-lab/runtime/certs/global-bundle.pem
rds() { run LAB_DATABASE_MODE=rds DB_HOST=manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com DB_SSLMODE=verify-full PGSSLROOTCERT="$ca" "$@"; }
reject_rds() {
  status=0
  rds "$@" >/dev/null 2>&1 || status=$?
  [ "$status" = 64 ] || { echo "FAIL: RDS guard returned $status instead of 64: $*"; exit 1; }
  count=$((count + 1))
}
reject_rds DB_HOST=production.fixture.us-east-1.rds.amazonaws.com
reject_rds DB_HOST=manaleague-eks-lab.fixture.us-west-2.rds.amazonaws.com
reject_rds DB_HOST=manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com.attacker.test
reject_rds DB_HOST=manaleague-eks-lab.fixture.us-east-1Xrds.amazonaws.com
reject_rds DB_HOST=manaleague-eks-lab..us-east-1.rds.amazonaws.com
reject_rds DB_HOST=manaleague-eks-lab.fixture.us-east-1.rds.amazonaws.com,postgres
reject_rds AWS_DEFAULT_REGION='us-east-1|us-west-2'
reject_rds AWS_DEFAULT_REGION=
reject_rds DB_SSLMODE=disable
reject_rds DB_SSLMODE=require
reject_rds DB_SSLMODE=prefer
reject_rds PGSSLROOTCERT=/tmp/other.pem
reject_rds PGSSLROOTCERT=
reject_rds DB_USERNAME=production
reject_rds DB_PORT=5433
run
rds
rds AWS_DEFAULT_REGION=sa-east-1 DB_HOST=manaleague-eks-lab.fixture.sa-east-1.rds.amazonaws.com
echo "PASS: $count unsafe runtime configurations refused; local and two literal-region RDS guard-only cases accepted (no database connections)"
