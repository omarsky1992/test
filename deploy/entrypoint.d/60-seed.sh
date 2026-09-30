#!/bin/sh
# Runs on every container start, after migrations. The seeder only adds missing reference
# data (plans, cash boxes, permissions, first admin), so it is safe to repeat.
# Skipped in containers without autorun (the scheduler), which start before migrations finish.
set -e
if [ "${AUTORUN_ENABLED:-true}" != "false" ]; then
    php /var/www/html/artisan db:seed --force --no-interaction
fi
