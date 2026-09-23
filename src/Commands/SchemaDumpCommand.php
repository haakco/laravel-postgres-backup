<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup\Commands;

use Haakco\PostgresBackup\Data\Connection;
use Haakco\PostgresBackup\DumpService;
use Illuminate\Console\Command;
use Throwable;

final class SchemaDumpCommand extends Command
{
    protected $signature = 'postgres-backup:schema {--connection= : Laravel PostgreSQL connection} {--path= : Output SQL file}';

    protected $description = 'Create a PostgreSQL schema dump for Laravel migrations';

    public function handle(DumpService $dumps): int
    {
        try {
            $name = (string) ($this->option('connection') ?: config('postgres-backup.connection', 'pgsql'));
            $path = (string) ($this->option('path') ?: config('postgres-backup.schema.path'));
            $created = $dumps->schema(
                Connection::fromLaravel($name),
                $path,
                (bool) config('postgres-backup.schema.include_data', false),
                (array) config('postgres-backup.schema.exclude_table_data', []),
                config('postgres-backup.schema.append_sql_file'),
            );
            $this->info("Schema dump created: {$created}");

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
