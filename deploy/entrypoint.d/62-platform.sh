#!/bin/sh
# Runs on every container start, after migrations: sets up the platform database (clients and
# subscriptions) and registers this system as client 1. It never changes client 1's data, and a
# failure here never stops the site from starting.
if [ "${AUTORUN_ENABLED:-true}" != "false" ]; then
    php /var/www/html/artisan platform:install --no-interaction || echo "platform:install failed; the site runs as before." >&2
fi
