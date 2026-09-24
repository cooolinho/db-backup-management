# syntax=docker/dockerfile:1
#
# Build from the repository root (the Laravel app lives in laravel/,
# but docker/s6 and docker/entrypoint.d below are copied from the root):
#   docker build -f docker/Dockerfile .
FROM serversideup/php:8.4-fpm-nginx

# --------------------------------------------------------------------------
# System packages: PHP extensions + database dump/restore clients.
#
# MariaDB's client (11.8 in Debian trixie) is used for BOTH the mariadb and
# mysql drivers: Debian ships no true Oracle MySQL client, and the MariaDB
# client is wire-compatible with MySQL 8.x/8.4 for the plain dump/restore
# operations this tool performs (no MySQL-8-only mysqldump flags are used).
# PostgreSQL 18's client isn't in Debian trixie yet, so it's pulled from the
# official PGDG apt repository.
# --------------------------------------------------------------------------
USER root

RUN install-php-extensions intl

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        mariadb-client \
        zip \
        unzip \
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

# --------------------------------------------------------------------------
# Application
# --------------------------------------------------------------------------
WORKDIR /var/www/html

COPY --chown=www-data:www-data laravel/ .

RUN composer install --no-dev --no-interaction --optimize-autoloader \
    && composer clear-cache

# --------------------------------------------------------------------------
# Background services: the Laravel scheduler (ticks the backup interval)
# and the queue worker (runs backup/restore/swap jobs) run as s6 services
# alongside nginx/php-fpm. app:ensure-admin and the APP_KEY bootstrap run
# once per container start from /etc/entrypoint.d (see docker/entrypoint.d).
# --------------------------------------------------------------------------
COPY docker/s6/laravel-scheduler /etc/s6-overlay/s6-rc.d/laravel-scheduler
COPY docker/s6/laravel-queue-worker /etc/s6-overlay/s6-rc.d/laravel-queue-worker
RUN chmod +x \
        /etc/s6-overlay/s6-rc.d/laravel-scheduler/run \
        /etc/s6-overlay/s6-rc.d/laravel-scheduler/start.sh \
        /etc/s6-overlay/s6-rc.d/laravel-queue-worker/run \
        /etc/s6-overlay/s6-rc.d/laravel-queue-worker/start.sh \
    && touch \
        /etc/s6-overlay/s6-rc.d/user/contents.d/laravel-scheduler \
        /etc/s6-overlay/s6-rc.d/user/contents.d/laravel-queue-worker

COPY docker/entrypoint.d/ /etc/entrypoint.d/
RUN chmod +x /etc/entrypoint.d/*.sh

# /data holds the tool's own SQLite database + APP_KEY (persist as a volume).
# /backups holds the dump files (persist as a volume or bind mount).
RUN mkdir -p /data /backups \
    && chown -R www-data:www-data /data /backups /var/www/html

ENV APP_DATABASE_PATH=/data/database.sqlite \
    BACKUP_PATH=/backups \
    AUTORUN_ENABLED=true \
    PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=2G \
    PHP_POST_MAX_SIZE=2G \
    NGINX_CLIENT_MAX_BODY_SIZE=2G

USER www-data

EXPOSE 8080
