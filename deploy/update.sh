#!/usr/bin/env bash
# Pulls the latest code and restarts. Database changes apply automatically on start.
set -euo pipefail
cd "$(dirname "$0")"
git pull --ff-only
# Keys for the WhatsApp QR service, created once (never shown, never committed).
for key in WAHA_API_KEY WAHA_WEBHOOK_SECRET; do
    if ! grep -q "^${key}=." .env; then
        sed -i "/^${key}=/d" .env
        echo "${key}=$(openssl rand -hex 32)" >> .env
    fi
done
docker compose --env-file .env up -d --build
docker image prune -f >/dev/null
echo "Updated."
