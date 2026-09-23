#!/bin/sh
# Runs the Laravel scheduler. The actual interval is stored in the app's
# Settings (overridable in the UI); this just ticks every minute so
# app/Console/Commands/ScheduledBackupTickCommand.php can act on it.
set -e
cd /var/www/html
exec php artisan schedule:work
