#!/bin/sh
set -eu
cd "$(dirname "$0")"
# Deliberately destroys only this explicitly named Compose project's disposable data.
docker compose --env-file .env.local -f compose.yaml down --volumes --remove-orphans
