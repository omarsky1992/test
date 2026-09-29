# Production image: PHP-FPM + Nginx (serversideup/php), listens on 8080.
FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions intl

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# Run migrations and cache config/routes/views on every start.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off
