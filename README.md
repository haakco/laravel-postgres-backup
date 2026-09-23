# Laravel PostgreSQL Backup

Configurable schema dumps, full SQL exports, and imports for Laravel. This package uses the installed `pg_dump` and `psql` clients. It does not schedule or retain backups; use your existing scheduler and storage owner for that.

## Install

Until the package is registered on Packagist and tagged, add this Composer repository in the consuming application:

```json
{"repositories": [{"type": "vcs", "url": "https://github.com/haakco/laravel-postgres-backup"}]}
```

Then install the current branch and publish its configuration:

```bash
composer require "haakco/laravel-postgres-backup:dev-main"
php artisan vendor:publish --tag=postgres-backup-config
```

Requires PHP 8.4+, Laravel 11–13, PostgreSQL client tools, and a configured Laravel `pgsql` connection. Configure credentials in Laravel's database connection or libpq's `.pgpass`; the package passes the password only through `PGPASSWORD` and never writes it to a dump or command line.

## Schema dump

```bash
php artisan postgres-backup:schema
php artisan postgres-backup:schema --connection=pgsql --path=database/schema/pgsql-schema.sql
```

The default produces `database/schema/pgsql-schema.sql` with schema only. Set `schema.include_data` to `true` when a Laravel migration baseline must also contain seed data, as in TrackLab and cb. Set `schema.exclude_table_data` for transient tables. The package removes PostgreSQL 18 `\restrict` and `\unrestrict` lines from generated schema files and can append an application-owned SQL file through `schema.append_sql_file` for TimescaleDB or other application-specific restore steps.

Schema generation writes a temporary file, verifies PostgreSQL's completion marker, and replaces the configured target only after success. It does not archive migrations or change Laravel's `schema:dump` command.

## Export and import

```bash
php artisan postgres-backup:export --environment=prod
php artisan postgres-backup:export --connection=pgsql --output=/safe/path/dump.sql.gz
php artisan postgres-backup:export --plain
php artisan postgres-backup:import /safe/path/dump.sql.gz --environment=local
```

`environments` maps friendly names to Laravel PostgreSQL connection names. Add `prod` and `qa` there only after configuring their connections in the application. The default `local` maps to `pgsql`. Full exports are compressed by default and include `--clean --if-exists`, so importing into an existing database replaces objects in the dump. Import validates the PostgreSQL header and completion marker before invoking `psql`, removes database creation and connection-switch commands from older `pg_dump --create` files, stops on the first SQL error, and requires confirmation unless `--force` is passed. Import does not create or drop the target database and does not stop application writers. Stop writers and prepare any TimescaleDB hooks through the application runbook before importing.

Configuration is published to `config/postgres-backup.php`: connection names, schema path and contents, export directory, excluded table data, SQL suffix, client binary paths, and process timeout. Use an application-specific suffix file for schema guards; do not put application table names or host addresses in this library.

## Development

```bash
composer install
composer check-all
```

The Justfile provides `just lint`, `just test`, and `just check` for the same checks. `just next-tag` previews the next version. `just release` creates and pushes that tag and opens a GitHub release after confirming that local `main` is clean and matches `origin/main`.
