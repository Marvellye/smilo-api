<?php

return [
    'app' => [
        'name'    => getenv('APP_NAME') ?: 'Smilo API',
        'env'     => getenv('APP_ENV') ?: 'local',
        'debug'   => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'port'    => (int) (getenv('APP_PORT') ?: 8000),
    ],
    'cors' => [
        'origin' => getenv('CORS_ORIGIN') ?: '*',
    ],
    'db' => [
        'driver' => getenv('DB_DRIVER') ?: 'sqlite',
        // MySQL
        'host'   => getenv('DB_HOST') ?: '127.0.0.1',
        'port'   => (int) (getenv('DB_PORT') ?: 3306),
        'name'   => getenv('DB_NAME') ?: 'smilo',
        'user'   => getenv('DB_USER') ?: 'root',
        'pass'   => getenv('DB_PASS') ?: '',
        // SQLite
        'sqlite_path' => __DIR__ . '/../../' . (getenv('DB_SQLITE_PATH') ?: 'storage/database/smilo.db'),
    ],
];
