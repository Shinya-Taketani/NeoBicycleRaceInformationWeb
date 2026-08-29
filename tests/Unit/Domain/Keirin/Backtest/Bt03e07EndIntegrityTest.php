<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourceIntegrityGuard;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e07DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e07OutcomeSnapshotEndVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e07ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class Bt03e07EndIntegrityTest extends TestCase
{
    public function test_feature_fingerprint_drift_stops_before_artifact_publication(): void
    {
        $publicationCalls = 0;
        $start = ['fingerprints' => [['content_fingerprint_sha256' => str_repeat('a', 64)]]];
        $end = ['fingerprints' => [['content_fingerprint_sha256' => str_repeat('b', 64)]]];

        try {
            (new Bt03e02SourceIntegrityGuard)->assertUnchanged($start, $end, 'BT-03E-07 fixed feature source');
            $publicationCalls++;
            $this->fail('A drifted feature fingerprint must fail before publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drifted', $exception->getMessage());
        }

        $this->assertSame(0, $publicationCalls);
    }

    public function test_service_performs_both_end_integrity_checks_before_publication(): void
    {
        $path = (new ReflectionClass(Bt03e07DevelopmentEvaluationService::class))->getFileName();
        $source = is_string($path) ? file_get_contents($path) : false;
        $this->assertIsString($source);

        $snapshotVerification = strpos($source, '$this->endSnapshotVerifier->verify');
        $featureVerification = strpos($source, '$this->integrity->assertUnchanged($featureStart, $featureEnd');
        $publication = strpos($source, '$this->artifacts->write');
        $this->assertIsInt($snapshotVerification);
        $this->assertIsInt($featureVerification);
        $this->assertIsInt($publication);
        $this->assertLessThan($publication, $snapshotVerification);
        $this->assertLessThan($publication, $featureVerification);
    }

    #[DataProvider('developmentYears')]
    public function test_same_length_one_byte_snapshot_drift_stops_before_artifact_publication(int $year): void
    {
        [$directory, $snapshot] = $this->snapshot();
        $audit = $this->auditReadyForEndVerification();
        $publicationCalls = 0;
        try {
            $path = $snapshot->partitionPath($year);
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $offset = strpos($contents, '"race_id":');
            $this->assertIsInt($offset);
            $originalLength = strlen($contents);
            $contents[$offset + strlen('"race_id":')] = '9';
            $this->assertSame($originalLength, strlen($contents));
            file_put_contents($path, $contents);

            try {
                (new Bt03e07OutcomeSnapshotEndVerifier)->verify(
                    Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e07/outcome'),
                    $audit,
                );
                $publicationCalls++;
                $this->fail("A drifted {$year} partition must fail before publication.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('partition identity did not match', $exception->getMessage());
            }

            $this->assertSame(0, $publicationCalls);
        } finally {
            if ($audit->active()) {
                try {
                    $audit->finish();
                } catch (RuntimeException) {
                    // A failed end verification intentionally leaves the audit incomplete.
                }
            }
            $this->remove($directory);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function developmentYears(): iterable
    {
        foreach (Bt03e07Contract::DEVELOPMENT_YEARS as $year) {
            yield (string) $year => [$year];
        }
    }

    private function auditReadyForEndVerification(): Bt03e07ReadOnlyQueryAudit
    {
        $audit = new Bt03e07ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();
        foreach (Bt03e07Contract::DEVELOPMENT_YEARS as $year) {
            $audit->recordFeatureSourceYear($year);
        }
        foreach (Bt03e07Contract::OUTER_YEARS as $year) {
            $audit->recordCandidateManifestSealed($year);
        }

        return $audit;
    }

    /** @return array{string,Bt02OutcomeContextSnapshotArtifact} */
    private function snapshot(): array
    {
        $directory = sys_get_temp_dir().'/bt03e07-end-snapshot-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $partitions = [];
        foreach (Bt03e07Contract::DEVELOPMENT_YEARS as $year) {
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

        return [$directory, Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e07/outcome')];
    }

    private function remove(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            unlink($directory.'/'.$file);
        }
        rmdir($directory);
    }
}
