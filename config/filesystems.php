<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Where dump files actually live (BACKUP_PATH), kept separate from
        // Laravel's own 'local'/'public' disks (Livewire temp uploads, etc.)
        'backups' => [
            'driver' => 'local',
            'root' => env('BACKUP_PATH', '/backups'),
            'throw' => true,
            'report' => false,
        ],

        // The optional second copy; reads BACKUP_S3_* (its own config, not
        // AWS_*) so it can point at any S3-compatible provider (MinIO,
        // Hetzner, ...), not just AWS.
        's3' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'eu-central-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'root' => env('BACKUP_S3_PREFIX', 'db-backups'),
            'endpoint' => env('BACKUP_S3_ENDPOINT') ?: null,
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
