#!/usr/bin/env bash
set -euo pipefail
helper="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/load-image.sh"
[ -f "$helper" ] || { echo 'FAIL: load-image.sh is missing' >&2; exit 1; }
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/bin"
export DOCKER_CALLS="$work/docker-calls"
cat > "$work/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$DOCKER_CALLS"
case "$*" in
  'load --input '*) exit "${LOAD_STATUS:-0}" ;;
  'image inspect manaleague-app:'*) exit "${INSPECT_STATUS:-0}" ;;
  *) echo 'unexpected docker command' >&2; exit 99 ;;
esac
MOCK
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH"
tag="manaleague-app:$(printf 'a%.0s' {1..40})"
printf 'hermetic archive fixture\n' > "$work/app.tar.gz"
(cd "$work" && sha256sum --text app.tar.gz > app.tar.gz.sha256)
expect_failure() {
  : > "$DOCKER_CALLS"
  if bash "$helper" "$@" > "$work/output" 2>&1; then
    echo "FAIL: expected rejection: $*" >&2; exit 1
  fi
}
expect_failure
[ ! -s "$DOCKER_CALLS" ]; echo 'PASS: arguments rejected before Docker'
expect_failure "$work/app.tar.gz" "$work/app.tar.gz.sha256" 'manaleague-app:latest'
[ ! -s "$DOCKER_CALLS" ]; echo 'PASS: non-commit tag rejected before Docker'
expect_failure "$work/missing.tar.gz" "$work/app.tar.gz.sha256" "$tag"
[ ! -s "$DOCKER_CALLS" ]; echo 'PASS: missing archive rejected before Docker'
expect_failure "$work/app.tar.gz" "$work/missing.sha256" "$tag"
[ ! -s "$DOCKER_CALLS" ]; echo 'PASS: missing checksum rejected before Docker'
printf 'tampered\n' >> "$work/app.tar.gz"
expect_failure "$work/app.tar.gz" "$work/app.tar.gz.sha256" "$tag"
[ ! -s "$DOCKER_CALLS" ]; echo 'PASS: checksum mismatch rejected before Docker'
(cd "$work" && sha256sum --text app.tar.gz > app.tar.gz.sha256)
export LOAD_STATUS=1
expect_failure "$work/app.tar.gz" "$work/app.tar.gz.sha256" "$tag"
[ "$(wc -l < "$DOCKER_CALLS")" -eq 1 ] || { cat "$work/output" >&2; exit 1; }; echo 'PASS: load failure stops before inspect'
unset LOAD_STATUS
export INSPECT_STATUS=1
expect_failure "$work/app.tar.gz" "$work/app.tar.gz.sha256" "$tag"
[ "$(wc -l < "$DOCKER_CALLS")" -eq 2 ]; echo 'PASS: missing expected tag rejected'
unset INSPECT_STATUS
: > "$DOCKER_CALLS"
# Invocation from another directory exercises archive/checksum path handling.
(cd "$work/bin" && bash "$helper" "$work/app.tar.gz" "$work/app.tar.gz.sha256" "$tag")
printf 'load --input %s\nimage inspect %s\n' "$work/app.tar.gz" "$tag" > "$work/expected"
cmp "$work/expected" "$DOCKER_CALLS"
echo 'PASS: valid archive loaded and exact commit tag inspected'
