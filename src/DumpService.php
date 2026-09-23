<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup;

use Haakco\PostgresBackup\Data\Connection;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DumpService
{
    /**
     * @param  list<string>  $excludeTableData
     */
    public function schema(Connection $connection, string $path, bool $includeData = false, array $excludeTableData = [], ?string $appendSqlFile = null): string
    {
        $this->ensureDirectory($path);
        $temporary = $this->temporaryPath($path);

        try {
            $this->runDump($connection, $temporary, ! $includeData, $excludeTableData);
            $this->requireCompleteDump($temporary);
            $this->sanitizeSchema($temporary);

            if ($appendSqlFile !== null) {
                if (! is_file($appendSqlFile) || ! is_readable($appendSqlFile)) {
                    throw new RuntimeException("Schema SQL suffix is not readable: {$appendSqlFile}");
                }
                $suffix = file_get_contents($appendSqlFile);
                if ($suffix === false || file_put_contents($temporary, "\n{$suffix}\n", FILE_APPEND) === false) {
                    throw new RuntimeException('Could not append schema SQL suffix.');
                }
            }

            $this->publish($temporary, $path);

            return $path;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param  list<string>  $excludeTableData
     */
    public function export(Connection $connection, string $path, bool $compress = true, array $excludeTableData = []): string
    {
        $this->ensureDirectory($path);
        $temporary = $this->temporaryPath($path);
        $plain = $compress ? $this->temporaryPath($path) : $temporary;

        try {
            $this->runDump($connection, $plain, false, $excludeTableData);
            $this->requireCompleteDump($plain);

            if ($compress) {
                $input = fopen($plain, 'rb');
                $output = gzopen($temporary, 'wb6');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Could not open export for compression.');
                }
                try {
                    while (! feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false || gzwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Could not compress database export.');
                        }
                    }
                } finally {
                    fclose($input);
                    gzclose($output);
                }
            }

            $this->publish($temporary, $path);

            return $path;
        } finally {
            @unlink($temporary);
            if ($plain !== $temporary) {
                @unlink($plain);
            }
        }
    }

    public function import(Connection $connection, string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Dump is not readable: {$path}");
        }

        $plain = $path;
        $temporary = null;

        try {
            if (str_ends_with($path, '.gz')) {
                $temporary = $this->temporaryPath($path);
                $input = gzopen($path, 'rb');
                $output = fopen($temporary, 'wb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Could not open compressed dump.');
                }
                try {
                    while (! gzeof($input)) {
                        $chunk = gzread($input, 65536);
                        if ($chunk === false || fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Could not decompress dump.');
                        }
                    }
                } finally {
                    gzclose($input);
                    fclose($output);
                }
                $plain = $temporary;
            }

            $this->requireCompleteDump($plain);
            $prepared = $this->stripDatabaseDirectives($plain);
            if ($prepared !== $plain) {
                if ($temporary !== null) {
                    @unlink($temporary);
                }
                $temporary = $prepared;
                $plain = $prepared;
            }
            $command = [
                (string) config('postgres-backup.psql', 'psql'),
                '-X', '--no-password', '--set=ON_ERROR_STOP=1',
                '--host='.$connection->host,
                '--port='.$connection->port,
                '--username='.$connection->username,
                '--dbname='.$connection->database,
                '--file='.$plain,
            ];
            $this->run($command, $connection);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /** @param list<string> $excludeTableData */
    private function runDump(Connection $connection, string $path, bool $schemaOnly, array $excludeTableData): void
    {
        $command = [
            (string) config('postgres-backup.pg_dump', 'pg_dump'),
            '--no-password', '--host='.$connection->host,
            '--port='.$connection->port,
            '--username='.$connection->username,
            '--dbname='.$connection->database,
            '--format=plain', '--clean', '--if-exists', '--no-owner', '--no-acl',
            '--quote-all-identifiers', '--no-tablespaces', '--file='.$path,
        ];
        if ($schemaOnly) {
            $command[] = '--schema-only';
        }
        foreach ($excludeTableData as $table) {
            $command[] = '--exclude-table-data='.$table;
        }

        $this->run($command, $connection);
    }

    /** @param list<string> $command */
    private function run(array $command, Connection $connection): void
    {
        $process = new Process($command, null, $connection->environment(), null, (int) config('postgres-backup.timeout_seconds', 3600));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf('%s failed (exit %d): %s', basename($command[0]), $process->getExitCode(), trim($process->getErrorOutput())));
        }
    }

    private function requireCompleteDump(string $path): void
    {
        $size = @filesize($path);
        if ($size === false || $size < 50) {
            throw new RuntimeException('Dump is empty or incomplete.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not read dump.');
        }
        try {
            $head = fread($stream, 4096);
            fseek($stream, max(0, $size - 4096));
            $tail = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($head) || ! is_string($tail) || ! str_contains($head, '-- PostgreSQL database dump') || ! str_contains($tail, '-- PostgreSQL database dump complete')) {
            throw new RuntimeException('Dump is missing PostgreSQL header or completion marker.');
        }
    }

    private function sanitizeSchema(string $path): void
    {
        $input = fopen($path, 'rb');
        $filteredPath = $this->temporaryPath($path);
        $output = fopen($filteredPath, 'wb');
        if ($input === false || $output === false) {
            throw new RuntimeException('Could not read schema dump.');
        }
        try {
            while (($line = fgets($input)) !== false) {
                if (preg_match('/^\\\\(un)?restrict\s/', $line) !== 1 && fwrite($output, $line) !== strlen($line)) {
                    throw new RuntimeException('Could not sanitize schema dump.');
                }
            }
            if (! feof($input)) {
                throw new RuntimeException('Could not finish reading schema dump.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        try {
            $this->publish($filteredPath, $path);
        } finally {
            @unlink($filteredPath);
        }
    }

    private function stripDatabaseDirectives(string $path): string
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not inspect dump.');
        }
        $prepared = $this->temporaryPath($path);
        $output = fopen($prepared, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Could not prepare dump.');
        }
        $changed = false;
        try {
            while (($line = fgets($input)) !== false) {
                if (preg_match('/^(CREATE|DROP|ALTER|COMMENT ON) DATABASE\b|^\\\\(connect|c)\b/i', $line) === 1) {
                    $changed = true;

                    continue;
                }
                if (fwrite($output, $line) !== strlen($line)) {
                    throw new RuntimeException('Could not prepare dump.');
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if (! $changed) {
            @unlink($prepared);

            return $path;
        }

        return $prepared;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }
    }

    private function temporaryPath(string $path): string
    {
        $temporary = tempnam(dirname($path), '.postgres-backup-');
        if ($temporary === false) {
            throw new RuntimeException("Could not create temporary file beside {$path}");
        }

        return $temporary;
    }

    private function publish(string $temporary, string $path): void
    {
        if (! rename($temporary, $path)) {
            throw new RuntimeException("Could not publish dump to {$path}");
        }
    }
}
