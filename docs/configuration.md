# Configuration

Publish the package configuration with `php artisan vendor:publish --tag=postgres-backup-config`. The resulting `config/postgres-backup.php` is the application-owned place for settings. Keep credentials in Laravel's database connection configuration or libpq's `.pgpass` file.

## Connection names

The package accepts **Laravel connection names**, not hostnames in command options. `connection` selects the default schema source. `environments` maps friendly export and import names to Laravel connections:

```php
'connection' => 'pgsql',
'environments' => [
    'local' => 'pgsql',
    'prod' => 'reporting_prod',
],
```

`reporting_prod` must already be a `pgsql` connection in the application's `config/database.php`. It can read its host, port, database, username, and password from the application's secret owner. The package rejects missing and non-PostgreSQL connections. `--connection=NAME` overrides the map for one command. `--environment=NAME` selects an entry from the map for export or import.

## Schema settings

| Setting | Default | Effect |
| --- | --- | --- |
| `schema.path` | `database/schema/pgsql-schema.sql` | File replaced after a successful dump. |
| `schema.include_data` | `false` | Includes table data when `true`; useful for a migration baseline with retained seed data. |
| `schema.exclude_table_data` | `[]` | `pg_dump` table patterns whose rows are omitted when data is included. Table definitions remain. |
| `schema.append_sql_file` | `null` | Path to application-owned SQL appended after schema generation. |

For an application that needs baseline data but not queue or session rows:

```php
'schema' => [
    'path' => database_path('schema/pgsql-schema.sql'),
    'include_data' => true,
    'exclude_table_data' => ['jobs', 'failed_jobs', 'sessions'],
    'append_sql_file' => database_path('schema/post-restore.sql'),
],
```

The package strips PostgreSQL `\restrict` and `\unrestrict` lines from its generated schema dump. The appended SQL is your application's responsibility. TrackLab and cb have different TimescaleDB objects and restore fixes; keep those statements beside each application, then test its generated schema with that application's fresh database workflow. This package does not archive migrations or replace their migration rollup commands.

## Export and import settings

| Setting | Default | Effect |
| --- | --- | --- |
| `exports.directory` | `storage/app/backups` | Directory for generated exports when `--output` is omitted. |
| `exports.exclude_table_data` | `[]` | Table patterns whose rows are omitted from full exports. |
| `pg_dump` | `pg_dump` | Path or command name for the PostgreSQL dump client. |
| `psql` | `psql` | Path or command name for the PostgreSQL import client. |
| `timeout_seconds` | `3600` | Maximum time for each external client process. |

Exports are plain SQL when `--plain` is supplied, otherwise gzip files. The default name combines the database name and current timestamp. Pass `--output=/path/file.sql.gz` to control the location. Avoid writing dumps under the web root or into Git. The package writes a temporary file in the target directory, validates it, and renames it into place; the destination directory must be writable and have enough space for the uncompressed intermediate file.

The package supplies `PGPASSWORD` from the selected Laravel connection to `pg_dump` or `psql` as a process environment value. It does not print or embed the password in command arguments or dumps. If no password is configured, libpq can use `.pgpass`. The clients run with `--no-password`, so a missing credential fails instead of waiting for an interactive prompt.
