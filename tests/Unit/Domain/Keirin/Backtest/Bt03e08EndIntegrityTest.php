<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourceIntegrityGuard;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e08DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e08OutcomeSnapshotEndVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e08ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class Bt03e08EndIntegrityTest extends TestCase
{
    public function test_feature_drift_and_service_order_prevent_publication(): void
    {
        $calls = 0;
        try {
            (new Bt03e02SourceIntegrityGuard)->assertUnchanged(['digest' => str_repeat('a', 64)], ['digest' => str_repeat('b', 64)], 'BT-03E-08 fixed feature source');
            $calls++;
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drifted', $exception->getMessage());
        }
        $this->assertSame(0, $calls);
        $path = (new ReflectionClass(Bt03e08DevelopmentEvaluationService::class))->getFileName();
        $source = file_get_contents((string) $path);
        $this->assertIsString($source);
        $publication = strpos($source, '$this->artifacts->write');
        $this->assertIsInt($publication);
        $this->assertLessThan($publication, strpos($source, '$this->endSnapshotVerifier->verify'));
        $this->assertLessThan($publication, strpos($source, '$this->integrity->assertUnchanged($featureStart, $featureEnd'));
    }

    #[DataProvider('years')]
    public function test_same_length_one_byte_partition_drift_is_rejected_for_every_year(int $year): void
    {
        [$directory, $snapshot] = $this->snapshot();
        $audit = $this->audit();
        try {
            $path = $snapshot->partitionPath($year);
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $offset = strpos($contents, '"race_id":');
            $this->assertIsInt($offset);
            $length = strlen($contents);
            $contents[$offset + strlen('"race_id":')] = '9';
            $this->assertSame($length, strlen($contents));
            file_put_contents($path, $contents);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('partition identity did not match');
            (new Bt03e08OutcomeSnapshotEndVerifier)->verify(Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e08/outcome'), $audit);
        } finally {
            if ($audit->active()) {
                try {
                    $audit->finish();
                } catch (RuntimeException) {
                }
            }
            $this->remove($directory);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function years(): iterable
    {
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            yield (string) $year => [$year];
        }
    }

    private function audit(): Bt03e08ReadOnlyQueryAudit
    {
        $audit = new Bt03e08ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $audit->recordFeatureSourceYear($year);
        }
        foreach (Bt03e08Contract::OUTER_YEARS as $year) {
            $audit->recordCandidateManifestSealed($year);
        }

        return $audit;
    }

    /** @return array{string,Bt02OutcomeContextSnapshotArtifact} */
    private function snapshot(): array
    {
        $directory = sys_get_temp_dir().'/bt03e08-end-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $partitions = [];
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $file = "{$year}.jsonl";
            $line = Bt02OutcomeContextSnapshotArtifact::encode(['format_version' => Bt02OutcomeContextSnapshotArtifact::FORMAT_VERSION, 'race_id' => $year - 2021, 'race_date' => "{$year}-06-01", 'scheduled_start_at' => "{$year}-06-01T12:00:00+09:00", 'sales_close_at' => null, 'entrant_count' => 5, 'race_status' => 'CONFIRMED', 'race_type' => 'Ａ級予選', 'results' => array_map(static fn (int $bike): array => ['bike_number' => $bike, 'rank' => $bike, 'result_status' => 'FINISHED'], range(1, 5))]);
            file_put_contents($directory.'/'.$file, $line);
            $partitions[] = new Bt02OutcomeSnapshotPartitionDto($year, $file, 1, 5, strlen($line), hash('sha256', $line));
        }
        $payload = Bt02OutcomeContextSnapshotArtifact::manifestPayload($partitions);
        $hash = hash('sha256', Bt02OutcomeContextSnapshotArtifact::encode($payload));
        file_put_contents($directory.'/manifest.json', Bt02OutcomeContextSnapshotArtifact::encode([...$payload, 'manifest_sha256' => $hash]));

        return [$directory, Bt02OutcomeContextSnapshotArtifact::open($directory, 'synthetic/bt03e08/outcome')];
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        } foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            unlink($directory.'/'.$file);
        } rmdir($directory);
    }
}
