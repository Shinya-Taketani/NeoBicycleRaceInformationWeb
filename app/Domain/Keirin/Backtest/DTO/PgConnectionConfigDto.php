<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use Illuminate\Support\Facades\DB;
use RuntimeException;

readonly class PgConnectionConfigDto
{
    public function __construct(
        public string $host,
        public string $port,
        public string $database,
        public string $username,
        public string $password,
        public string $sslMode = 'prefer',
    ) {}

    public static function fromLaravel(): self
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('BT-02 fingerprint preflight requires PostgreSQL.');
        }

        return new self(
            (string) $connection->getConfig('host'),
            (string) $connection->getConfig('port'),
            (string) $connection->getConfig('database'),
            (string) $connection->getConfig('username'),
            (string) $connection->getConfig('password'),
            (string) ($connection->getConfig('sslmode') ?? 'prefer'),
        );
    }

    /** @return array<string, string> */
    public function processEnvironment(): array
    {
        return [
            'PGHOST' => $this->host,
            'PGPORT' => $this->port,
            'PGDATABASE' => $this->database,
            'PGUSER' => $this->username,
            'PGPASSWORD' => $this->password,
            'PGSSLMODE' => $this->sslMode,
            'PGCLIENTENCODING' => 'UTF8',
            'PGOPTIONS' => '-c default_transaction_read_only=on',
            'LC_ALL' => 'C',
        ];
    }
}
