<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup\Tests;

use Haakco\PostgresBackup\Data\Connection;
use Haakco\PostgresBackup\DumpService;
use Haakco\PostgresBackup\PostgresBackupServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class DumpServiceTest extends TestCase
{
    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [PostgresBackupServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/postgres-backup-test-'.bin2hex(random_bytes(5));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_schema_dump_is_configurable_and_sanitized(): void
    {
        $this->fakeDump();
        $suffix = $this->directory.'/suffix.sql';
        file_put_contents($suffix, 'SELECT 1;');
        $path = $this->directory.'/schema.sql';

        (new DumpService)->schema($this->connection(), $path, true, ['jobs'], $suffix);

        $this->assertStringContainsString('-- PostgreSQL database dump complete', (string) file_get_contents($path));
        $this->assertStringContainsString('SELECT 1;', (string) file_get_contents($path));
        $this->assertStringNotContainsString('\restrict', (string) file_get_contents($path));
        $this->assertStringContainsString('--exclude-table-data=jobs', (string) file_get_contents($this->directory.'/args'));
        $this->assertStringNotContainsString('--schema-only', (string) file_get_contents($this->directory.'/args'));
    }

    public function test_export_failure_keeps_previous_dump(): void
    {
        $path = $this->directory.'/backup.sql.gz';
        file_put_contents($path, 'previous backup');
        $script = $this->directory.'/pg_dump';
        file_put_contents($script, "#!/bin/sh\necho 'connection failed' >&2\nexit 1\n");
        chmod($script, 0755);
        config()->set('postgres-backup.pg_dump', $script);

        try {
            (new DumpService)->export($this->connection(), $path);
            $this->fail('Expected export to fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('connection failed', $error->getMessage());
            $this->assertSame('previous backup', file_get_contents($path));
        }
    }

    public function test_compressed_export_can_be_imported_after_validation(): void
    {
        $this->fakeDump();
        $psql = $this->directory.'/psql';
        file_put_contents($psql, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '".$this->directory."/import-args'\n");
        chmod($psql, 0755);
        config()->set('postgres-backup.psql', $psql);
        $path = $this->directory.'/backup.sql.gz';
        $service = new DumpService;

        $service->export($this->connection(), $path);
        $this->assertStringContainsString('-- PostgreSQL database dump complete', (string) gzdecode((string) file_get_contents($path)));
        $service->import($this->connection(), $path);
        $this->assertStringContainsString('--dbname=example', (string) file_get_contents($this->directory.'/import-args'));

        file_put_contents($path, gzencode('broken dump'));
        $this->expectException(RuntimeException::class);
        $service->import($this->connection(), $path);
    }

    public function test_import_strips_database_switch_from_existing_dump(): void
    {
        $psql = $this->directory.'/psql';
        file_put_contents($psql, "#!/bin/sh\nfor arg in \"\$@\"; do\n  case \"\$arg\" in --file=*) cp \"\${arg#--file=}\" '".$this->directory."/imported.sql';; esac\ndone\n");
        chmod($psql, 0755);
        config()->set('postgres-backup.psql', $psql);
        $path = $this->directory.'/switch.sql';
        file_put_contents($path, "-- PostgreSQL database dump\nCREATE DATABASE other;\n\\connect other\n-- PostgreSQL database dump complete\n");

        (new DumpService)->import($this->connection(), $path);
        $sql = (string) file_get_contents($this->directory.'/imported.sql');
        $this->assertStringNotContainsString('CREATE DATABASE', $sql);
        $this->assertStringNotContainsString('\connect', $sql);
        $this->assertStringContainsString('-- PostgreSQL database dump complete', $sql);
    }

    private function connection(): Connection
    {
        return new Connection('127.0.0.1', 5432, 'example', 'tester', null);
    }

    private function fakeDump(): void
    {
        $script = $this->directory.'/pg_dump';
        file_put_contents($script, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '".$this->directory."/args'\nfor arg in \"\$@\"; do\n  case \"\$arg\" in --file=*) output=\"\${arg#--file=}\";; esac\ndone\nprintf '%s\\n' '-- PostgreSQL database dump' '\\restrict secret' 'CREATE TABLE example (id integer);' '\\unrestrict secret' '-- PostgreSQL database dump complete' > \"\$output\"\n");
        chmod($script, 0755);
        config()->set('postgres-backup.pg_dump', $script);
    }
}
