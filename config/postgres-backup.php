<?php

declare(strict_types=1);

return [
    'connection' => env('POSTGRES_BACKUP_CONNECTION', 'pgsql'),
    'environments' => [
        'local' => env('POSTGRES_BACKUP_LOCAL_CONNECTION', 'pgsql'),
    ],
    'schema' => [
        'path' => database_path('schema/pgsql-schema.sql'),
        'include_data' => false,
        'exclude_table_data' => [],
        'append_sql_file' => null,
    ],
    'exports' => [
        'directory' => storage_path('app/backups'),
        'exclude_table_data' => [],
    ],
    'pg_dump' => 'pg_dump',
    'psql' => 'psql',
    'timeout_seconds' => 3600,
];
