<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\DTO\BoundedProcessResultDto;
use App\Domain\Keirin\Backtest\DTO\PgConnectionConfigDto;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Support\BoundedProcessRunner;
use App\Domain\Keirin\Backtest\Support\Bt02FingerprintCopySql;
use RuntimeException;

class PgCopyFingerprintRunner implements Bt02FingerprintRunner
{
    public const REQUIRED_CLIENT_VERSION = '18.6';

    public const REQUIRED_SERVER_VERSION_NUM = '180006';

    public function __construct(
        private readonly ?PgConnectionConfigDto $connection = null,
        private readonly ?string $psqlBinary = null,
        private readonly BoundedProcessRunner $processRunner = new BoundedProcessRunner,
        private readonly Bt02FingerprintCopySql $copySql = new Bt02FingerprintCopySql,
    ) {}

    public function assertVersionContract(): void
    {
        $clientOutput = '';
        $client = $this->processRunner->run(
            [$this->binary(), '--version'],
            ['LC_ALL' => 'C'],
            null,
            function (string $chunk) use (&$clientOutput): void {
                $clientOutput .= $chunk;
            },
        );
        if ($client->exitCode !== 0
            || preg_match('/^psql \(PostgreSQL\) ([0-9]+\.[0-9]+)(?:\s|$)/', trim($clientOutput), $matches) !== 1
            || ($matches[1] ?? null) !== self::REQUIRED_CLIENT_VERSION) {
            throw new RuntimeException('BT-02 fingerprint requires psql client 18.6.');
        }

        $serverOutput = '';
        $server = $this->runPsql("SHOW server_version_num;\n", function (string $chunk) use (&$serverOutput): void {
            $serverOutput .= $chunk;
        });
        if ($server->exitCode !== 0 || trim($serverOutput) !== self::REQUIRED_SERVER_VERSION_NUM) {
            throw new RuntimeException('BT-02 fingerprint requires PostgreSQL server 18.6.');
        }
    }

    public function fingerprint(int $runId, Bt02FingerprintType $type): string
    {
        $hash = hash_init('sha256');
        $result = $this->runPsql(
            $this->copySql->for($runId, $type),
            fn (string $chunk) => hash_update($hash, $chunk),
        );
        if ($result->exitCode !== 0) {
            throw new RuntimeException('BT-02 psql COPY failed: '.$this->safeError($result->stderr));
        }
        if ($result->stdoutBytes === 0) {
            throw new RuntimeException('BT-02 psql COPY returned an empty byte stream.');
        }

        return hash_final($hash);
    }

    private function runPsql(string $sql, callable $stdoutConsumer): BoundedProcessResultDto
    {
        return $this->processRunner->run(
            [$this->binary(), '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1'],
            $this->connection()->processEnvironment(),
            $sql,
            $stdoutConsumer,
        );
    }

    private function connection(): PgConnectionConfigDto
    {
        return $this->connection ?? PgConnectionConfigDto::fromLaravel();
    }

    private function binary(): string
    {
        return $this->psqlBinary ?? (getenv('BT02_PSQL_BINARY') ?: '/usr/pgsql-18/bin/psql');
    }

    private function safeError(string $stderr): string
    {
        return $stderr === '' ? 'no stderr was provided' : mb_substr($stderr, 0, 1000);
    }
}
