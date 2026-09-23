<?php

declare(strict_types=1);

namespace Haakco\PostgresBackup\Data;

use InvalidArgumentException;

final readonly class Connection
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public ?string $password,
    ) {
        if ($host === '' || $database === '' || $username === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('PostgreSQL connection needs a host, port, database and username.');
        }
    }

    public static function fromLaravel(string $name): self
    {
        $config = config("database.connections.{$name}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new InvalidArgumentException("Connection '{$name}' is not a configured PostgreSQL connection.");
        }

        $host = $config['host'] ?? '127.0.0.1';
        if (is_array($host)) {
            $host = $host[0] ?? '';
        }

        return new self(
            (string) $host,
            (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''),
            (string) ($config['username'] ?? ''),
            isset($config['password']) ? (string) $config['password'] : null,
        );
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        $environment = ['PGCONNECT_TIMEOUT' => '30', 'PGPASSWORD' => $this->password ?? ''];

        return $environment;
    }
}
