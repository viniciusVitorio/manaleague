#!/bin/sh
# Optional offline/local TLS integration. No AWS/RDS endpoint is contacted.
set -eu
cd "$(dirname "$0")"
# Run from the Linux build context; mount paths must be native to the Docker daemon.
fixture=$(mktemp -d "$PWD/.tls-fixture.XXXXXX")
name="manaleague-eks-lab-tls-$$"
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$fixture"
}
trap cleanup EXIT INT TERM
password=$(openssl rand -hex 32)
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 1 -subj /CN=Lab-TLS-Fixture-CA -keyout "$fixture/ca.key" -out "$fixture/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=postgres -keyout "$fixture/server.key" -out "$fixture/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:postgres\nextendedKeyUsage=serverAuth\n' > "$fixture/extensions.cnf"
openssl x509 -req -in "$fixture/server.csr" -CA "$fixture/ca.pem" -CAkey "$fixture/ca.key" -CAcreateserial -days 1 -sha256 -extfile "$fixture/extensions.cnf" -out "$fixture/server.crt" >/dev/null 2>&1
chmod 0755 "$fixture"
chmod 0644 "$fixture/ca.pem"
docker network create "$name" >/dev/null
docker run -d --rm --name "$name" --network "$name" --network-alias postgres --network-alias wrong-postgres \
    -e POSTGRES_DB=manaleague_eks_lab -e POSTGRES_USER=manaleague_lab -e POSTGRES_PASSWORD="$password" \
    -v "$fixture:/tls:ro" --entrypoint sh postgres:17.6-alpine -c '
        cp /tls/server.key /tmp/lab-server.key
        cp /tls/server.crt /tmp/lab-server.crt
        chown postgres:postgres /tmp/lab-server.key /tmp/lab-server.crt
        chmod 0600 /tmp/lab-server.key
        exec docker-entrypoint.sh postgres -c ssl=on -c ssl_cert_file=/tmp/lab-server.crt -c ssl_key_file=/tmp/lab-server.key
    ' >/dev/null
ready=0
for attempt in $(seq 1 60); do
    if docker exec "$name" pg_isready -h 127.0.0.1 -U manaleague_lab -d manaleague_eks_lab >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" = 1 ] || { docker logs "$name"; exit 1; }
check() {
    docker run --rm --read-only --network "$name" --entrypoint php \
        -e TEST_DB_PASSWORD="$password" -e PGCONNECT_TIMEOUT=3 -e PGSSLROOTCERT="$2" \
        -v "$fixture/ca.pem:/tmp/lab-ca.pem:ro" -v "$PWD/test-tls-pdo.php:/tmp/test-tls-pdo.php:ro" \
        lab-app:dev /tmp/test-tls-pdo.php "$1"
}
check trusted /tmp/lab-ca.pem
check wrong-host /tmp/lab-ca.pem
check untrusted /var/www/html/infra/eks-lab/runtime/certs/global-bundle.pem
check missing-ca /tmp/no-such-ca.pem
echo 'PASS: four real local TLS/PDO cases; fixture cleanup automatic; no AWS connectivity tested'
