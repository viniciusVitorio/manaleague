#!/usr/bin/env bash
# Run as root on the deployment host, before switching the current symlink.
set -euo pipefail
if [[ $# -ne 3 ]]; then
  echo 'Usage: load-image.sh ARCHIVE CHECKSUM manaleague-app:COMMIT_SHA' >&2
  exit 1
fi
archive="$1"
checksum="$2"
tag="$3"
[[ "$tag" =~ ^manaleague-app:[0-9a-f]{40}$ ]] || { echo 'Expected a full commit image tag' >&2; exit 1; }
[[ -f "$archive" && -s "$archive" ]] || { echo 'Missing or empty image archive' >&2; exit 1; }
[[ -f "$checksum" ]] || { echo 'Missing image checksum' >&2; exit 1; }
# Accept exactly the sha256sum record for this archive, not arbitrary paths.
record="$(cat -- "$checksum")"
digest="${record%% *}"
[[ "$digest" =~ ^[0-9a-f]{64}$ && "$record" == "$digest  $(basename -- "$archive")" ]] || {
  echo 'Invalid image checksum record' >&2; exit 1;
}
printf '%s  %s\n' "$digest" "$archive" | sha256sum --check --strict -
docker load --input "$archive"
docker image inspect "$tag" >/dev/null
printf 'Imported %s\n' "$tag"
