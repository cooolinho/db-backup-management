#!/bin/sh
###################################################
# Usage: 15-app-storage.sh
###################################################
# Ensures the tool's persistent volumes exist and the SQLite database
# file is present before migrations run in 50-laravel-automations.sh.
set -e

: "${APP_DATABASE_PATH:=/data/database.sqlite}"
: "${BACKUP_PATH:=/backups}"

mkdir -p "$(dirname "$APP_DATABASE_PATH")" "$BACKUP_PATH"

if [ ! -f "$APP_DATABASE_PATH" ]; then
    echo "🗄️  app-storage: creating $APP_DATABASE_PATH"
    touch "$APP_DATABASE_PATH"
fi
