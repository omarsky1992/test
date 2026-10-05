#!/usr/bin/env bash
# One-time setup on a fresh Ubuntu VPS. Run from the repository:  sudo bash deploy/setup.sh
# Installs Docker, writes deploy/.env, optionally copies the data from the old (Neon) database,
# then builds and starts the system with HTTPS.
set -euo pipefail
cd "$(dirname "$0")"

if [ -f .env ]; then
    echo "deploy/.env already exists. To update the system run:  bash deploy/update.sh"
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "==> Installing Docker"
    curl -fsSL https://get.docker.com | sh
fi

ip=$(curl -4fsS --max-time 10 https://api.ipify.org || hostname -I | awk '{print $1}')
default_domain="${ip//./-}.sslip.io"

echo
read -rp "Domain (Enter = ${default_domain}): " domain
domain=${domain:-$default_domain}
read -rp "APP_KEY copied from Render (Enter = new system without old data): " app_key
while true; do
    read -rsp "Password for the admin account (English letters and numbers, 8+): " admin_password; echo
    [[ "$admin_password" =~ ^[A-Za-z0-9@._-]{8,}$ ]] && break
    echo "Use at least 8 English letters, numbers or @ . _ -"
done
read -rsp "Old database DB_URL from Render to copy the data (Enter = skip): " old_db; echo

if [ -z "$app_key" ]; then
    [ -n "$old_db" ] && { echo "Copying old data needs the old APP_KEY, or the stored account passwords can't be read."; exit 1; }
    app_key="base64:$(openssl rand -base64 32)"
fi

set_env() { K="$1" V="$2" awk 'BEGIN{k=ENVIRON["K"]; v=ENVIRON["V"]} index($0, k"=")==1 {print k"="v; next} {print}' .env > .env.tmp && mv .env.tmp .env; }
cp .env.example .env
set_env APP_KEY "$app_key"
set_env APP_DOMAIN "$domain"
set_env APP_URL "https://$domain"
set_env DB_PASSWORD "$(openssl rand -hex 24)"
set_env ADMIN_PASSWORD "$admin_password"
set_env BACKUP_TRIGGER_TOKEN "$(openssl rand -hex 24)"
set_env WAHA_API_KEY "$(openssl rand -hex 32)"
set_env WAHA_WEBHOOK_SECRET "$(openssl rand -hex 32)"
chmod 600 .env
mkdir -p backups

compose() { docker compose --env-file .env "$@"; }

if [ -n "$old_db" ]; then
    echo "==> Copying the data from the old database"
    compose up -d db
    until compose exec -T db pg_isready -U subs -d subs >/dev/null 2>&1; do sleep 2; done
    if ! compose exec -T db pg_dump "$old_db" -Fc --no-owner --no-acl > backups/old-database.dump; then
        echo "Could not read the old database. Check DB_URL, then run this script again."
        compose down; rm -f .env backups/old-database.dump
        exit 1
    fi
    compose exec -T db pg_restore --no-owner --no-acl -U subs -d subs < backups/old-database.dump \
        || echo "(pg_restore reported warnings; checking the result)"
    echo "Subscribers copied: $(compose exec -T db psql -U subs -d subs -tAc 'select count(*) from subscribers')"
fi

echo "==> Building and starting (the first build takes a few minutes)"
compose up -d --build

echo
echo "Done. Open:  https://$domain"
echo "Sign in as: admin  (with the password you entered, or your old password if you copied the data)"
