#!/bin/sh
###################################################
# Usage: 60-app-init.sh
###################################################
# Runs after 50-laravel-automations.sh (migrations + caches are done by
# then): creates the first admin user if the users table is still empty.
set -e

: "${APP_BASE_DIR:=/var/www/html}"

php "$APP_BASE_DIR/artisan" app:ensure-admin
