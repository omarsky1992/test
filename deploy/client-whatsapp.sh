#!/usr/bin/env bash
# Gives one client its own WhatsApp (QR) service, so its manager links the client's own phone from
# its WhatsApp settings. Client 1's phone and service are never touched.
#   bash ~/subs/deploy/client-whatsapp.sh <client-slug>
# Safe to run again: the keys are made once (kept encrypted in the platform database) and the
# service is only (re)started.
set -euo pipefail
cd "$(dirname "$0")"

slug="${1:-}"
if ! [[ "$slug" =~ ^[a-z][a-z0-9-]{1,28}[a-z0-9]$ ]]; then
    echo "Usage: bash client-whatsapp.sh <client-slug>   (the client's address name, e.g. alnoor)" >&2
    exit 1
fi
service="waha-$slug"

# The keys, from the app (which checks the client exists and is not client 1).
mkdir -p clients
chmod 700 clients
keys=$(docker compose --env-file .env exec -T app php artisan platform:whatsapp "$slug" --url="http://$service:3000" --no-interaction) || {
    echo "$keys" >&2
    exit 1
}
if ! grep -q '^WAHA_API_KEY=.' <<<"$keys"; then
    echo "No key returned: $keys" >&2
    exit 1
fi
umask 077
grep '^WAHA_API_KEY=' <<<"$keys" > "clients/$service.env"

# One service per client, written from every clients/waha-*.env (same image and settings as client 1's).
image=$(awk '/^  waha:/{found=1} found && /image:/{print $2; exit}' docker-compose.yml)
{
    echo "# Written by client-whatsapp.sh: one WhatsApp (QR) service per client. Do not edit by hand."
    echo "services:"
    for file in clients/waha-*.env; do
        name=$(basename "$file" .env)
        cat <<YAML
  $name:
    image: $image
    restart: unless-stopped
    env_file: $file
    environment:
      WHATSAPP_DEFAULT_ENGINE: GOWS
      WAHA_BASE_URL: http://$name:3000
      WAHA_DASHBOARD_ENABLED: "false"
      WHATSAPP_SWAGGER_ENABLED: "false"
      WHATSAPP_RESTART_ALL_SESSIONS: "true"
      TZ: Asia/Baghdad
    volumes:
      - ${name}_sessions:/app/.sessions
      - ${name}_media:/app/.media
YAML
    done
    echo "volumes:"
    for file in clients/waha-*.env; do
        name=$(basename "$file" .env)
        echo "  ${name}_sessions:"
        echo "  ${name}_media:"
    done
} > docker-compose.clients.yml

# Every docker compose command (update.sh included) now uses both files.
if ! grep -q '^COMPOSE_FILE=' .env; then
    echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.clients.yml' >> .env
fi
docker compose --env-file .env up -d "$service"
echo "WhatsApp service for «$slug» is running. Its manager links the phone from: WhatsApp settings → QR code."
