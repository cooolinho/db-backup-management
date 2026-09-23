#!/bin/sh
###################################################
# Usage: 20-app-key.sh
###################################################
# Ensures an APP_KEY is available before Laravel boots in
# 50-laravel-automations.sh. Generated once and persisted to the /data
# volume so it survives container recreation (an explicit APP_KEY env
# var, if set, always wins and is never overwritten).
set -e
script_name="app-key"

: "${APP_BASE_DIR:=/var/www/html}"
key_file="$(dirname "${APP_DATABASE_PATH:-/data/database.sqlite}")/app.key"
env_file="$APP_BASE_DIR/.env"

if [ -n "$APP_KEY" ]; then
    exit 0
fi

mkdir -p "$(dirname "$key_file")"

if [ -s "$key_file" ]; then
    generated_key="$(cat "$key_file")"
else
    echo "🔑 $script_name: generating a new APP_KEY, persisted to $key_file"
    generated_key="$(php "$APP_BASE_DIR/artisan" key:generate --show)"
    printf '%s' "$generated_key" > "$key_file"
    chmod 600 "$key_file"
fi

# /var/www/html is the container's writable layer, not a volume: it is
# gone on every container recreation. Every php process (php-fpm, artisan,
# the queue worker, the scheduler) loads its own .env from disk on boot,
# so (re)writing it here — instead of trying to inject an env var into
# already-started sibling processes — reaches all of them reliably.
if [ ! -f "$env_file" ] || ! grep -q '^APP_KEY=' "$env_file" 2>/dev/null; then
    printf 'APP_KEY=%s\n' "$generated_key" >> "$env_file"
fi
