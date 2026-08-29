<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourceIntegrityGuard;
use App\Domain\Keirin\Backtest\Services\Bt03e06DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e06OutcomeSnapshotEndVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e06ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class Bt03e06EndIntegrityTest extends TestCase
{
    public function test_same_length_outcome_drift_is_rejected_before_publication(): void
    {
        [$directory, $snapshot] = $this->snapshot();
        $audit = $this->auditReadyForOutcome();
        try {
            $audit->recordSnapshotYear(2024);
            $snapshot->verifyPartition(2024);
            $path = $snapshot->partitionPath(2024);
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $offset = strpos($contents, 'CONFIRMED');
            $this->assertIsInt($offset);
            $contents[$offset] = 'X';
            file_put_contents($path, $contents);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('partition identity did not match');
            (new Bt03e06OutcomeSnapshotEndVerifier)->verify(
                Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e06/outcome'),
                $audit,
            );
        } finally {
            if ($audit->active()) {
                try {
                    $audit->finish();
                } catch (RuntimeException) {
                    // The failed end verification must also fail the final audit.
                }
            }
            $this->remove($directory);
        }
    }

    public function test_feature_fingerprint_drift_and_end_checks_precede_publication(): void
    {
        $start = ['fingerprints' => [['content_fingerprint_sha256' => str_repeat('a', 64)]]];
        $end = ['fingerprints' => [['content_fingerprint_sha256' => str_repeat('b', 64)]]];
        try {
            (new Bt03e02SourceIntegrityGuard)->assertUnchanged($start, $end, 'BT-03E-06 fixed feature source');
            $this->fail('A one-byte feature fingerprint drift must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drifted', $exception->getMessage());
        }

        $path = (new ReflectionClass(Bt03e06DevelopmentEvaluationService::class))->getFileName();
        $source = is_string($path) ? file_get_contents($path) : false;
        $this->assertIsString($source);
        $snapshotCheck = strpos($source, '$this->endSnapshotVerifier->verify');
        $featureCheck = strpos($source, '$featureEnd = $this->preflight->run()');
        $publication = strpos($source, '$this->artifacts->write');
        $this->assertIsInt($snapshotCheck);
        $this->assertIsInt($featureCheck);
        $this->assertIsInt($publication);
        $this->assertLessThan($publication, $snapshotCheck);
        $this->assertLessThan($publication, $featureCheck);
    }

    private function auditReadyForOutcome(): Bt03e06ReadOnlyQueryAudit
    {
        $audit = new Bt03e06ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();
        foreach ([2024, 2025] as $year) {
            $audit->recordFeatureSourceYear($year);
            $audit->recordCandidateManifestSealed($year);
        }

        return $audit;
    }

    /** @return array{string,Bt02OutcomeContextSnapshotArtifact} */
    private function snapshot(): array
    {
        $directory = sys_get_temp_dir().'/bt03e06-end-snapshot-'.bin2hex(random_bytes(8));
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
                'results' => array_map(static fn (int $bike): array => [
                    'bike_number' => $bike,
                    'rank' => $bike,
                    'result_status' => 'FINISHED',
                ], range(1, 5)),
            ]);
            file_put_contents($directory.'/'.$file, $line);
            $partitions[] = new Bt02OutcomeSnapshotPartitionDto($year, $file, 1, 5, strlen($line), hash('sha256', $line));
        }
        $payload = Bt02OutcomeContextSnapshotArtifact::manifestPayload($partitions);
        $hash = hash('sha256', Bt02OutcomeContextSnapshotArtifact::encode($payload));
        file_put_contents($directory.'/manifest.json', Bt02OutcomeContextSnapshotArtifact::encode([...$payload, 'manifest_sha256' => $hash]));

        return [$directory, Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e06/outcome')];
    }

    private function remove(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            unlink($directory.'/'.$file);
        }
        rmdir($directory);
    }
}
