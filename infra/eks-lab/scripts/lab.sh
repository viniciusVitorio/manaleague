#!/usr/bin/env bash
set -euo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if command -v python3 >/dev/null 2>&1; then
  exec python3 "$here/lab.py" "$@"
elif command -v python >/dev/null 2>&1; then
  exec python "$here/lab.py" "$@"
else
  echo 'Python 3.10+ is required (run inside Linux/WSL).' >&2
  exit 127
fi
