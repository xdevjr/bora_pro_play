# syntax=docker/dockerfile:1.7

FROM composer:2.8 AS vendor

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-scripts

FROM oven/bun:1.2.15-alpine AS frontend

WORKDIR /var/www/html

COPY package.json bun.lock vite.config.js ./
RUN bun install --frozen-lockfile

COPY --from=vendor /var/www/html/vendor ./vendor
COPY resources ./resources
COPY public ./public

RUN bun run build

FROM php:8.5-fpm-bookworm AS runtime

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    curl \
    libicu-dev \
    libonig-dev \
    libpq-dev \
    libsqlite3-dev \
    nginx \
    supervisor \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    intl \
    mbstring \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=frontend /var/www/html/public/build ./public/build
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/conf.d/99-app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p \
    /run/nginx \
    /var/cache/nginx \
    /var/log/supervisor \
    bootstrap/cache \
    database \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    && chown -R www-data:www-data \
    bootstrap/cache \
    database \
    storage

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl --fail http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["app"]
