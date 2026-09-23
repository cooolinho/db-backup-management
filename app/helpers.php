<?php

use Pdo\Mysql;

if (! function_exists('target_database_connection')) {
    /**
     * Build a Laravel database connection array for the project database
     * that this tool backs up and restores.
     *
     * The driver is selected at runtime via DB_CONNECTION (mysql|mariadb|pgsql),
     * so the project's own .env database block can be copied here unchanged.
     * Used for both the 'target' (app-user) and 'target_root' (privileged)
     * connections in config/database.php, which differ only in credentials.
     */
    function target_database_connection(string $driver, string $username, string $password): array
    {
        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'app'),
                'username' => $username,
                'password' => $password,
                'charset' => env('DB_CHARSET', 'utf8'),
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => env('DB_SSLMODE', 'prefer'),
            ],
            default => [
                // 'mysql' or 'mariadb'
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'app'),
                'username' => $username,
                'password' => $password,
                'unix_socket' => env('DB_SOCKET', ''),
                'charset' => env('DB_CHARSET', 'utf8mb4'),
                'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => extension_loaded('pdo_mysql') ? array_filter([
                    Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                ]) : [],
            ],
        };
    }
}
