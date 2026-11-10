FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libzip-dev clamdscan unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring pcntl opcache zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/funeral.ini
WORKDIR /var/www/html

FROM php-base AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY . .
RUN mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
    && npm ci --include=dev \
    && npm run build \
    && rm -rf node_modules

FROM php-base AS app
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
CMD ["php-fpm"]

FROM nginx:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/up || exit 1

