<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup\Commands;

use Haakco\PostgresBackup\Data\Connection;
use Haakco\PostgresBackup\DumpService;
use Illuminate\Console\Command;
use Throwable;

final class ImportCommand extends Command
{
    protected $signature = 'postgres-backup:import {file : Plain SQL or .sql.gz dump} {--environment=local : Named connection in postgres-backup.environments} {--connection= : Laravel PostgreSQL connection override} {--force : Skip confirmation}';

    protected $description = 'Import a PostgreSQL dump into an existing database';

    public function handle(DumpService $dumps): int
    {
        try {
            $environment = (string) $this->option('environment');
            $name = (string) ($this->option('connection') ?: config("postgres-backup.environments.{$environment}"));
            if ($name === '') {
                throw new \InvalidArgumentException("Unknown backup environment: {$environment}");
            }
            $connection = Connection::fromLaravel($name);
            $this->warn("Import will change {$connection->database} on {$connection->host}:{$connection->port}.");
            if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
                return self::FAILURE;
            }
            $dumps->import($connection, (string) $this->argument('file'));
            $this->info('Database import completed.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
