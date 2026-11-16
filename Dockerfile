# syntax=docker/dockerfile:1.7
#
# Multi-stage image for the Audit Log Platform.
#   base   -> PHP 8.4-FPM with every extension the platform needs
#   dev    -> base + source mounted from the host (used by docker-compose.yml)
#   prod   -> base + code and production vendor baked in
#   web    -> nginx with the public assets
#   node-tools -> Node 24 (node:24-alpine) for the Node tooling: the OpenAPI lint
#             (`make openapi-lint`, CI). The dashboard is Filament, whose
#             assets ship with the package, so no front-end build is needed;
#             if one is added later, it is built in this stage.
#
# The PHP and Node versions are contractual (plan §2) - do not change them.

ARG PHP_VERSION=8.4

############################
# base: PHP 8.4-FPM
############################
FROM php:${PHP_VERSION}-fpm-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libfcgi-bin procps mariadb-client age \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

############################
# dev: source is bind-mounted
############################
FROM base AS dev
COPY docker/php/conf.d/opcache-dev.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
# pcov: code coverage for `php artisan test --coverage` (CI gate: API >= 80%).
RUN pecl install pcov && docker-php-ext-enable pcov && rm -rf /tmp/pear

############################
# prod: immutable image
############################
FROM base AS prod
COPY docker/php/conf.d/opcache-prod.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/app/public storage/app/backup-spool storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

############################
# web: nginx with the public assets of the prod image (production only;
# in development nginx mounts ./public instead)
############################
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public

############################
# node-tools: Node 24 tooling (plan §2 / §3: node:24-alpine)
############################
FROM node:24-alpine AS node-tools
WORKDIR /app
RUN npm install --global --no-audit --no-fund @redocly/cli@1.34.3
COPY package.json .nvmrc ./
COPY docs/openapi.yaml docs/openapi.yaml
CMD ["redocly", "lint", "docs/openapi.yaml", "--extends=minimal"]
