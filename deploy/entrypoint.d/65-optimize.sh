#!/bin/sh
# Runs on every container start. Caches Filament's components and icons so pages
# don't rebuild them on each request.
set -e
php /var/www/html/artisan filament:optimize --no-interaction
