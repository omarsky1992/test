#!/bin/sh
# Runs on every container start, after migrations. The seeder only adds missing reference
# data (plans, cash boxes, permissions, first admin), so it is safe to repeat.
set -e
php /var/www/html/artisan db:seed --force --no-interaction
