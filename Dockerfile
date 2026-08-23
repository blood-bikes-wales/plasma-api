# Production image for Cloud Run. Local development stays on Laravel Sail.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative


FROM dunglas/frankenphp:1-php8.5

WORKDIR /app

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    bcmath \
    opcache

COPY --from=vendor /app /app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache/data \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV SERVER_NAME=:8080

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
