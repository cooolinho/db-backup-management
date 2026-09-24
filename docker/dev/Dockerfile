# syntax=docker/dockerfile:1
#
# Development/CI image: PHP CLI + Composer + the same MariaDB/PostgreSQL
# client tooling as the production image (docker/Dockerfile), so
# `composer test` and `composer test:integration` run against real
# database fixtures without installing PHP locally. Not used in production
# - the production image is the nginx/php-fpm one at docker/Dockerfile.
FROM php:8.4-cli-trixie

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        libicu-dev \
        libpq-dev \
        libsodium-dev \
        libzip-dev \
        mariadb-client \
        unzip \
        zip \
    && docker-php-ext-install pdo_mysql pdo_pgsql intl zip sodium \
    && install -d /usr/share/postgresql-common/pgdg-keyrings \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg-keyrings/pgdg-archive-keyring.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg-keyrings/pgdg-archive-keyring.asc] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        postgresql-client-18 \
    && apt-get purge -y --auto-remove gnupg \
    && rm -rf /var/lib/apt/lists/* /etc/apt/sources.list.d/pgdg.list

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
