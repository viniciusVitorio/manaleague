#!/bin/sh
# Disposable real HTTP/PDO regression. No compose project or cloud resources touched.
set -eu
name="manaleague-readiness-$(date +%s)-$$"
work=$(mktemp -d "${TMPDIR:-/tmp}/lab-readiness.XXXXXX")
paused=0
network= db= app= web=
cleanup() {
    if [ "$paused" = 1 ] && [ -n "$db" ]; then docker unpause "$db" >/dev/null 2>&1 || true; fi
    # IDs are recorded only after successful creation: never delete by a guessed name.
    for id in "$web" "$app" "$db"; do
        [ -z "$id" ] || docker rm -fv "$id" >/dev/null 2>&1 || true
    done
    [ -z "$network" ] || docker network rm "$network" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
umask 077
printf 'APP_ENV=eks-lab\nAPP_KEY=base64:%s\nDB_CONNECTION=pgsql\nLAB_DATABASE_MODE=local\nDB_HOST=postgres\nDB_PORT=5432\nDB_DATABASE=manaleague_eks_lab\nDB_USERNAME=manaleague_lab\nDB_PASSWORD=%s\nDB_SSLMODE=disable\nLOG_CHANNEL=stderr\n' "$(openssl rand -base64 32)" "$(openssl rand -hex 32)" > "$work/app.env"
password=$(sed -n 's/^DB_PASSWORD=//p' "$work/app.env")
network=$(docker network create "$name")
db=$(docker create --name "$name-db" --network "$network" --network-alias postgres \
    -e POSTGRES_DB=manaleague_eks_lab -e POSTGRES_USER=manaleague_lab -e POSTGRES_PASSWORD="$password" \
    postgres:17.6-alpine)
docker start "$db" >/dev/null
for attempt in $(seq 1 60); do
    if docker exec "$name-db" pg_isready -h 127.0.0.1 -U manaleague_lab -d manaleague_eks_lab >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$name-db" pg_isready -h 127.0.0.1 -U manaleague_lab -d manaleague_eks_lab >/dev/null
app=$(docker create --name "$name-app" --network "$network" --env-file "$work/app.env" \
    --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /tmp:uid=82,gid=82,mode=1777 \
    --tmpfs /var/www/html/storage:uid=82,gid=82,mode=0770 \
    --tmpfs /var/www/html/bootstrap/cache:uid=82,gid=82,mode=0770 \
    -p 127.0.0.1::8080 "${APP_IMAGE:-lab-app:dev}")
docker start "$app" >/dev/null
web=$(docker create --name "$name-web" --network "container:$app" \
    -e APP_FASTCGI=127.0.0.1:9000 -e LAB_VPC_CIDR=10.77.0.0/16 \
    -e NGINX_WORKER_PROCESSES=1 "${WEB_IMAGE:-lab-web:dev}")
docker start "$web" >/dev/null
docker inspect "$app" "$web" --format 'TESTED {{.Image}}'
echo "FIXTURE network=$network db=$db app=$app web=$web"
port=$(docker port "$name-app" 8080/tcp | sed 's/.*://')
base="http://127.0.0.1:$port"
for attempt in $(seq 1 60); do
    if curl -fsS --max-time 3 "$base/readyz" >/dev/null 2>&1; then break; fi
    sleep 1
done
check() {
    path=$1 expected=$2 budget=$3
    result=$(curl -sS -o "$work/body" -w '%{http_code} %{time_total}' --max-time "$budget" "$base$path") || {
        echo "FAIL: $path exceeded ${budget}s budget ($result)"; return 1;
    }
    code=${result%% *}
    [ "$code" = "$expected" ] || { echo "FAIL: $path expected $expected got $result"; return 1; }
    body=$(cat "$work/body")
    if [ "$expected" = 200 ]; then wanted=OK; else wanted=Unavailable; fi
    [ "$body" = "$wanted" ] || { echo "FAIL: $path response is not sanitized"; return 1; }
    echo "PASS: $path $result (budget ${budget}s)"
}
check /readyz 200 3
docker exec "$app" sh infra/eks-lab/local/test-health.sh
docker exec "$app" sh infra/eks-lab/local/test-runtime.sh
docker exec "$app" sh infra/eks-lab/local/test-rds-guards.sh
ip=$(docker exec "$name-app" php -r 'echo gethostbyname("postgres");')
paused=1
docker pause "$name-db" >/dev/null
[ "$(docker exec "$name-app" php -r 'echo gethostbyname("postgres");')" = "$ip" ]
# The kernel still accepts TCP, but PostgreSQL cannot complete its handshake.
docker exec "$name-app" php -r '$s=fsockopen("postgres",5432,$e,$m,1); if (!$s) exit(1); fclose($s); echo "PASS: paused SQL retains DNS/IP and accepts TCP\n";'
check /readyz 503 3
check /up 200 3
# Repeated failures must not leave probe workers behind.
check /readyz 503 3
docker exec "$name-app" sh -c '! ps -o args | grep "[p]hp .*health.php --database-probe"'
echo 'PASS: repeated deadline failures leave no CLI probe workers'
docker unpause "$name-db" >/dev/null
paused=0
check /readyz 200 3
check /up 200 3
# Also exercise the original stop fixture: DNS behaviour varies with host resolver.
docker stop "$name-db" >/dev/null
check /readyz 503 3
check /up 200 3
docker exec "$app" sh -c '! ps -o args | grep "[p]hp .*health.php --database-probe"'
docker start "$db" >/dev/null
for attempt in $(seq 1 60); do
    if docker exec "$db" pg_isready -h 127.0.0.1 -U manaleague_lab -d manaleague_eks_lab >/dev/null 2>&1; then break; fi
    sleep 1
done
check /readyz 200 3
check /up 200 3
echo 'PASS: real SQL handshake outage, DNS-loss fixture, recovery, liveness, worker cleanup'
