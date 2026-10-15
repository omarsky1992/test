#!/usr/bin/env bash
# Pulls the latest code and restarts. Database changes apply automatically on start.
set -euo pipefail
cd "$(dirname "$0")"
# A copy of the database before anything changes (new code, migrations), kept in backups/pre-update.
if [ -n "$(docker compose --env-file .env ps -q db 2>/dev/null)" ]; then
    mkdir -p backups/pre-update
    file="backups/pre-update/$(date +%Y%m%d-%H%M%S).sql.gz"
    if docker compose --env-file .env exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$file" && [ -s "$file" ]; then
        echo "Backup: $file"
        ls -1t backups/pre-update/*.sql.gz | tail -n +11 | xargs -r rm -f
    else
        rm -f "$file"
        echo "Backup failed: update stopped, nothing was changed." >&2
        exit 1
    fi
fi
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
