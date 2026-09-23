<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup\Commands;

use Haakco\PostgresBackup\Data\Connection;
use Haakco\PostgresBackup\DumpService;
use Illuminate\Console\Command;
use Throwable;

final class ExportCommand extends Command
{
    protected $signature = 'postgres-backup:export {--environment=local : Named connection in postgres-backup.environments} {--connection= : Laravel PostgreSQL connection override} {--output= : Output file} {--plain : Write plain SQL instead of gzip}';

    protected $description = 'Export a PostgreSQL database';

    public function handle(DumpService $dumps): int
    {
        try {
            $environment = (string) $this->option('environment');
            $name = (string) ($this->option('connection') ?: config("postgres-backup.environments.{$environment}"));
            if ($name === '') {
                throw new \InvalidArgumentException("Unknown backup environment: {$environment}");
            }
            $connection = Connection::fromLaravel($name);
            $compressed = ! $this->option('plain');
            $path = (string) ($this->option('output') ?: rtrim((string) config('postgres-backup.exports.directory'), '/').'/'.$connection->database.'_'.date('Ymd_His').'.sql'.($compressed ? '.gz' : ''));
            $created = $dumps->export($connection, $path, $compressed, (array) config('postgres-backup.exports.exclude_table_data', []));
            $this->info("Database export created: {$created}");

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
