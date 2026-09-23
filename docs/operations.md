# Operations

These commands run from the consuming Laravel application's root. See [configuration](configuration.md) before using a nonlocal connection.

## Create a schema dump

```bash
php artisan postgres-backup:schema --connection=pgsql
```

The command calls `pg_dump`, checks for PostgreSQL's completion marker, removes `\restrict` and `\unrestrict` lines, appends the configured application SQL file, then replaces the configured schema path. A failed dump leaves the previous target file in place. Review the generated file and run the application's `migrate:fresh` or test database bootstrap against a disposable database before adopting it as a baseline.

The default contains schema only. Applications that need data in the baseline must set `schema.include_data=true` and list transient tables under `schema.exclude_table_data`. Migration archival, migration-list metadata, TimescaleDB table repairs, and connection-specific schema links remain application-owned.

## Export a database

```bash
php artisan postgres-backup:export --environment=prod
php artisan postgres-backup:export --environment=prod --output=/safe/path/export.sql.gz
```

The export uses a consistent `pg_dump` snapshot and writes plain SQL with object cleanup statements. It omits database creation statements, so the dump can be imported into a different existing database. A gzip export is the default. A failed or incomplete export does not replace an existing file at the requested output path.

Check the result before treating it as a backup:

```bash
gzip -t /safe/path/export.sql.gz
gunzip -c /safe/path/export.sql.gz | tail -n 5
```

The tail should contain `-- PostgreSQL database dump complete`. The package checks that marker itself, but only a test import into a disposable database proves that the SQL and application-specific extensions can be restored. Store verified exports with the application's approved backup and retention system; this package only writes local files.

## Import a database

```bash
php artisan postgres-backup:import /safe/path/export.sql.gz --environment=local
```

The command displays the target host and database and asks for confirmation. `--force` skips that prompt for an already reviewed automated operation. Import validates the dump before invoking `psql`, removes database creation and `\connect` commands from older `pg_dump --create` files, and stops on the first SQL error.

Import changes objects in the **existing target database**. The dump's `--clean` statements drop and recreate objects it contains; objects absent from the dump may remain. The package does not stop application writers, recreate the target database, run TimescaleDB pre/post restore hooks, or roll back a partial import. The consuming application's runbook must coordinate those steps. Use a disposable target to rehearse a full restore before relying on it for recovery.

## Development and release

`just check` runs Pint, Rector dry run, PHPStan, and PHPUnit. CI also runs Composer validation and ShellCheck. `just next-tag` only previews the next tag. `just release` tags and publishes a GitHub release from a clean `main` that matches `origin/main`; it makes external changes and should be run only for an authorized release.
