#!/bin/sh
# Runs backup/restore/swap jobs. --tries=1 is deliberate: if a job dies
# mid-run (crash, OOM, container restart) it is marked failed instead of
# silently re-running a partially applied backup or restore.
set -e
cd /var/www/html
exec php artisan queue:work \
    --queue=default \
    --tries=1 \
    --timeout="${BACKUP_JOB_TIMEOUT:-7200}" \
    --sleep=3 \
    --max-time=0
