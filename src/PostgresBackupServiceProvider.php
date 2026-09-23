<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup;

use Haakco\PostgresBackup\Commands\ExportCommand;
use Haakco\PostgresBackup\Commands\ImportCommand;
use Haakco\PostgresBackup\Commands\SchemaDumpCommand;
use Illuminate\Support\ServiceProvider;

final class PostgresBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/postgres-backup.php', 'postgres-backup');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/postgres-backup.php' => config_path('postgres-backup.php'),
            ], 'postgres-backup-config');

            $this->commands([SchemaDumpCommand::class, ExportCommand::class, ImportCommand::class]);
        }
    }
}
