<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\DTO\PgConnectionConfigDto;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Repositories\PgCopyFingerprintRunner;
use App\Domain\Keirin\Backtest\Support\BoundedProcessRunner;
use App\Domain\Keirin\Backtest\Support\Bt02FingerprintCopySql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bt02PgCopyFingerprintIntegrationTest extends TestCase
{
    private string $psqlBinary;

    private PgConnectionConfigDto $connection;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('BT02_PG_INTEGRATION') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('BT-02 COPY fingerprint integration requires an isolated PostgreSQL 18.4 database.');
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->psqlBinary = getenv('BT02_PSQL_BINARY') ?: '/usr/pgsql-18/bin/psql';
        $this->connection = PgConnectionConfigDto::fromLaravel();
    }

    public function test_runner_matches_direct_psql_sha256sum_and_detects_content_changes(): void
    {
        $runId = $this->seedFixture();
        $runner = new PgCopyFingerprintRunner($this->connection, $this->psqlBinary);
        $runner->assertVersionContract();

        $source = $runner->fingerprint($runId, Bt02FingerprintType::Source);
        $content = $runner->fingerprint($runId, Bt02FingerprintType::Content);
        $this->assertSame($this->directDigest($runId, Bt02FingerprintType::Source), $source);
        $this->assertSame($this->directDigest($runId, Bt02FingerprintType::Content), $content);

        $sourceBytes = $this->copyBytes($runId, Bt02FingerprintType::Source);
        $this->assertStringContainsString('\\N', $sourceBytes);
        $this->assertStringContainsString('選手/α', $sourceBytes);
        $this->assertStringEndsWith("\n", $sourceBytes);

        DB::table('statistic_feature_results')->where('feature_run_id', $runId)->where('subject_key', '選手/α')->update([
            'features' => json_encode(['unicode' => '変更後', 'slash' => '/still/unescaped'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->assertSame($source, $runner->fingerprint($runId, Bt02FingerprintType::Source));
        $this->assertNotSame($content, $runner->fingerprint($runId, Bt02FingerprintType::Content));
    }

    private function seedFixture(): int
    {
        $runId = (int) DB::table('statistic_feature_runs')->insertGetId([
            'run_uuid' => '00000000-0000-4000-8000-000000000301',
            'stat_code' => 'STAT-08',
            'calculation_version' => 'STAT-08-existing-db-v1',
            'mode' => 'BACKFILL',
            'status' => 'SUCCEEDED',
            'history_from' => '2022-01-01',
            'target_from' => '2023-01-01',
            'target_to' => '2023-12-31',
            'input_as_of_policy' => 'STRICTLY_BEFORE_RACE',
            'parameters' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'target_race_count' => 3,
            'processed_race_count' => 3,
            'target_entry_count' => 3,
            'success_count' => 3,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $base = [
            'feature_run_id' => $runId,
            'stat_code' => 'STAT-08',
            'calculation_version' => 'STAT-08-existing-db-v1',
            'subject_type' => 'RACE_ENTRY',
            'status' => 'VALID',
            'quality_status' => 'FULL',
            'acquisition_mode' => 'BACKFILL',
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('statistic_feature_results')->insert([
            [...$base,
                'subject_key' => '選手/α', 'race_id' => 10, 'race_entry_id' => null, 'player_id' => null,
                'features' => json_encode(['unicode' => '日本語', 'slash' => '/path/value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'evidence' => json_encode(['quoted' => 'a"b', 'null' => null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'input_hash' => str_repeat('a', 64), 'raw_points' => null, 'confidence' => null, 'effective_points' => null,
            ],
            [...$base,
                'subject_key' => 'decimal', 'race_id' => 10, 'race_entry_id' => 20, 'player_id' => 30,
                'features' => json_encode(['value' => 0.0001], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'evidence' => json_encode(['items' => [1, 2, 3]], JSON_THROW_ON_ERROR),
                'input_hash' => str_repeat('b', 64), 'raw_points' => '12.340000', 'confidence' => '0.87500000', 'effective_points' => '-1.250000',
            ],
            [...$base,
                'subject_key' => 'later', 'race_id' => 11, 'race_entry_id' => 21, 'player_id' => null,
                'features' => json_encode(['enabled' => true], JSON_THROW_ON_ERROR),
                'evidence' => json_encode([], JSON_THROW_ON_ERROR),
                'input_hash' => str_repeat('c', 64), 'raw_points' => '0.000000', 'confidence' => '1.00000000', 'effective_points' => '0.000000',
            ],
        ]);

        return $runId;
    }

    private function directDigest(int $runId, Bt02FingerprintType $type): string
    {
        $sqlPath = tempnam(sys_get_temp_dir(), 'bt02-copy-sql-');
        file_put_contents($sqlPath, (new Bt02FingerprintCopySql)->for($runId, $type));
        try {
            $pipeline = implode(' ', array_map('escapeshellarg', [
                $this->psqlBinary, '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1',
            ])).' < '.escapeshellarg($sqlPath).' | /usr/bin/sha256sum';
            $stdout = '';
            $result = (new BoundedProcessRunner)->run(
                ['/bin/bash', '-o', 'pipefail', '-c', $pipeline],
                $this->connection->processEnvironment(),
                null,
                function (string $chunk) use (&$stdout): void {
                    $stdout .= $chunk;
                },
            );
            $this->assertSame(0, $result->exitCode, $result->stderr);
            $digest = strtok(trim($stdout), " \t");
            $this->assertIsString($digest);

            return $digest;
        } finally {
            @unlink($sqlPath);
        }
    }

    private function copyBytes(int $runId, Bt02FingerprintType $type): string
    {
        $stdout = '';
        $result = (new BoundedProcessRunner)->run(
            [$this->psqlBinary, '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1'],
            $this->connection->processEnvironment(),
            (new Bt02FingerprintCopySql)->for($runId, $type),
            function (string $chunk) use (&$stdout): void {
                $stdout .= $chunk;
            },
        );
        $this->assertSame(0, $result->exitCode, $result->stderr);

        return $stdout;
    }
}
