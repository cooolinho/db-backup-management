#!/bin/sh
###################################################
# Usage: 20-app-key.sh
###################################################
# Ensures an APP_KEY is available before Laravel boots in
# 50-laravel-automations.sh. Generated once and persisted to the /data
# volume so it survives container recreation (an explicit APP_KEY env
# var, if set, always wins and is never overwritten).
#
# This does NOT write the generated key into .env: APP_KEY="" from
# env_file (see .env.example) already exists as a real environment
# variable by the time this script runs, and Laravel's Dotenv repository
# never overwrites an existing variable, even an empty one — appending
# to .env here would silently have no effect. Instead, config/app.php
# reads /data/app.key directly as a fallback (see persisted_app_key() in
# app/helpers.php) whenever APP_KEY is unset.
set -e
script_name="app-key"

: "${APP_BASE_DIR:=/var/www/html}"
key_file="$(dirname "${APP_DATABASE_PATH:-/data/database.sqlite}")/app.key"

if [ -n "$APP_KEY" ]; then
    exit 0
fi

mkdir -p "$(dirname "$key_file")"

if [ ! -s "$key_file" ]; then
    echo "🔑 $script_name: generating a new APP_KEY, persisted to $key_file"
    generated_key="$(php "$APP_BASE_DIR/artisan" key:generate --show)"
    printf '%s' "$generated_key" > "$key_file"
    chmod 600 "$key_file"
fi
