<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\Services\Bt03e04DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e04OutcomeSnapshotEndVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class Bt03e04OutcomeSnapshotEndVerifierTest extends TestCase
{
    public function test_reopened_end_snapshot_verifies_only_2024_and_2025_before_returning_its_identity(): void
    {
        [$directory, $snapshot] = $this->snapshot();
        $audit = $this->startedAudit();
        try {
            $endSnapshot = Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e04/outcome');
            $end = (new Bt03e04OutcomeSnapshotEndVerifier)->verify($endSnapshot, $audit);
            $access = $audit->finish()['snapshot_partition_access'];

            $this->assertSame($snapshot->auditParameters(), $end);
            $this->assertSame(0, $access[2022]);
            $this->assertSame(0, $access[2023]);
            $this->assertGreaterThan(0, $access[2024]);
            $this->assertGreaterThan(0, $access[2025]);
            $this->assertSame(0, $access[2026]);
        } finally {
            if ($audit->active()) {
                $audit->finish();
            }
            $this->remove($directory);
        }
    }

    public function test_partition_drift_after_evaluation_read_fails_before_artifact_publication(): void
    {
        [$directory, $snapshot] = $this->snapshot();
        $audit = $this->startedAudit();
        try {
            $audit->recordSnapshotYear(2024);
            $snapshot->verifyPartition(2024);
            $partitionPath = $snapshot->partitionPath(2024);
            $contents = file_get_contents($partitionPath);
            $this->assertIsString($contents);
            $offset = strpos($contents, 'CONFIRMED');
            $this->assertIsInt($offset);
            $contents[$offset] = 'X';
            file_put_contents($partitionPath, $contents);

            try {
                $endSnapshot = Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e04/outcome');
                (new Bt03e04OutcomeSnapshotEndVerifier)->verify($endSnapshot, $audit);
                $this->fail('A drifted partition must fail before an end identity can be returned.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('partition identity did not match', $exception->getMessage());
            }

            $servicePath = (new ReflectionClass(Bt03e04DevelopmentEvaluationService::class))->getFileName();
            $this->assertIsString($servicePath);
            $serviceSource = file_get_contents($servicePath);
            $this->assertIsString($serviceSource);
            $verifyOffset = strpos($serviceSource, '$this->endSnapshotVerifier->verify');
            $publishOffset = strpos($serviceSource, '$this->artifacts->write');
            $this->assertIsInt($verifyOffset);
            $this->assertIsInt($publishOffset);
            $this->assertLessThan($publishOffset, $verifyOffset);
        } finally {
            if ($audit->active()) {
                $access = $audit->finish()['snapshot_partition_access'];
                $this->assertSame(0, $access[2022]);
                $this->assertSame(0, $access[2023]);
                $this->assertGreaterThan(0, $access[2024]);
                $this->assertSame(0, $access[2026]);
            }
            $this->remove($directory);
        }
    }

    private function startedAudit(): Bt03e04ReadOnlyQueryAudit
    {
        $audit = new Bt03e04ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordDecoderContractFrozen();
        $audit->recordSourceBundleValidated();

        return $audit;
    }

    /** @return array{string,Bt02OutcomeContextSnapshotArtifact} */
    private function snapshot(): array
    {
        $directory = sys_get_temp_dir().'/bt03e04-end-snapshot-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $partitions = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $file = "{$year}.jsonl";
            $line = Bt02OutcomeContextSnapshotArtifact::encode([
                'format_version' => Bt02OutcomeContextSnapshotArtifact::FORMAT_VERSION,
                'race_id' => $year - 2021,
                'race_date' => "{$year}-06-01",
                'scheduled_start_at' => "{$year}-06-01T12:00:00+09:00",
                'sales_close_at' => null,
                'entrant_count' => 5,
                'race_status' => 'CONFIRMED',
                'race_type' => 'Ａ級予選',
                'results' => array_map(
                    static fn (int $bike): array => [
                        'bike_number' => $bike,
                        'rank' => $bike,
                        'result_status' => 'FINISHED',
                    ],
                    range(1, 5),
                ),
            ]);
            file_put_contents($directory.'/'.$file, $line);
            $partitions[] = new Bt02OutcomeSnapshotPartitionDto(
                $year,
                $file,
                1,
                5,
                strlen($line),
                hash('sha256', $line),
            );
        }
        $payload = Bt02OutcomeContextSnapshotArtifact::manifestPayload($partitions);
        $hash = hash('sha256', Bt02OutcomeContextSnapshotArtifact::encode($payload));
        file_put_contents(
            $directory.'/manifest.json',
            Bt02OutcomeContextSnapshotArtifact::encode([...$payload, 'manifest_sha256' => $hash]),
        );

        return [$directory, Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e04/outcome')];
    }

    private function remove(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            unlink($directory.'/'.$file);
        }
        rmdir($directory);
    }
}
