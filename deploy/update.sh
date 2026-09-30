#!/usr/bin/env bash
# Pulls the latest code and restarts. Database changes apply automatically on start.
set -euo pipefail
cd "$(dirname "$0")"
git pull --ff-only
docker compose --env-file .env up -d --build
docker image prune -f >/dev/null
echo "Updated."
