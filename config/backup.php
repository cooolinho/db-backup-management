<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Target database
    |--------------------------------------------------------------------------
    |
    | The driver of the project database being backed up (mysql|mariadb|pgsql).
    | Used by the DriverFactory to pick the right DatabaseDriver implementation;
    | the connections themselves are configured in config/database.php.
    |
    */

    'driver' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Storage defaults
    |--------------------------------------------------------------------------
    |
    | These are the defaults used the first time the app boots (and written
    | into the Settings record). From then on the UI's settings page is the
    | source of truth; changing these env values later has no effect unless
    | the settings are reset.
    |
    */

    'path' => env('BACKUP_PATH', '/backups'),

    'defaults' => [
        // Cron expression, null/empty = scheduler disabled.
        'schedule' => env('BACKUP_SCHEDULE', '0 3 * * *'),
        // sql | sql.gz | zip | tar.gz
        'format' => env('BACKUP_FORMAT', 'sql.gz'),
        'keep_local' => (int) env('BACKUP_KEEP_LOCAL', 14),
        'keep_s3' => (int) env('BACKUP_KEEP_S3', 60),
    ],

    'job_timeout' => (int) env('BACKUP_JOB_TIMEOUT', 7200),

    /*
    |--------------------------------------------------------------------------
    | S3-compatible storage
    |--------------------------------------------------------------------------
    */

    's3' => [
        'enabled' => (bool) env('BACKUP_S3_ENABLED', false),
        'key' => env('BACKUP_S3_KEY'),
        'secret' => env('BACKUP_S3_SECRET'),
        'region' => env('BACKUP_S3_REGION', 'eu-central-1'),
        'bucket' => env('BACKUP_S3_BUCKET'),
        'endpoint' => env('BACKUP_S3_ENDPOINT'),
        'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
        'prefix' => env('BACKUP_S3_PREFIX', 'db-backups'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin account
    |--------------------------------------------------------------------------
    |
    | Used once by `app:ensure-admin` (run from the Docker entrypoint) to
    | create the first user when no user exists yet. Has no effect after that.
    |
    */

    'admin' => [
        'name' => env('BACKUP_ADMIN_NAME', 'Admin'),
        'email' => env('BACKUP_ADMIN_EMAIL'),
        'password' => env('BACKUP_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */

    'upload_max' => env('BACKUP_UPLOAD_MAX', '2G'),

    /*
    |--------------------------------------------------------------------------
    | Failure notifications
    |--------------------------------------------------------------------------
    */

    'notify' => [
        'mail' => env('BACKUP_NOTIFY_MAIL'),
        'webhook_url' => env('BACKUP_NOTIFY_WEBHOOK_URL'),
        // slack | discord | generic
        'webhook_type' => env('BACKUP_NOTIFY_WEBHOOK_TYPE', 'slack'),
    ],

];
