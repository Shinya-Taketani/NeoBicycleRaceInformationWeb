<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextSourceRowDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Repositories\Bt02OutcomeContextSnapshotSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotBuilder;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use DateTimeImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Bt02OutcomeContextSnapshotTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bt02-outcome-snapshot-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    public function test_snapshot_is_deterministic_partitioned_persistent_and_replayable(): void
    {
        $first = $this->build($this->rows());
        $second = $this->build($this->rows());

        $this->assertSame($first->manifestHash(), $second->manifestHash());
        $this->assertSame($first->auditParameters(), $second->auditParameters());
        $this->assertSame('BT02-OUTCOME-CONTEXT-SNAPSHOT-JSONL-v1', $first->auditParameters()['outcome_snapshot_format_version']);
        $this->assertSame([2022, 2023, 2024, 2025], array_column($first->auditParameters()['outcome_snapshot_partitions'], 'year'));
        $this->assertSame([1, 1, 1, 1], array_column($first->auditParameters()['outcome_snapshot_partitions'], 'race_count'));
        $this->assertSame([5, 5, 5, 5], array_column($first->auditParameters()['outcome_snapshot_partitions'], 'result_row_count'));
        $this->assertSame(1, count(glob($this->directory.'/*', GLOB_ONLYDIR) ?: []), 'The same sealed identity must reuse one persistent artifact.');

        $fold = new FoldDefinitionDto('ALL', 1, null, null, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2025-12-31'));
        $races = array_merge(...iterator_to_array($first->chunks($fold, 2), false));
        $this->assertSame([1, 2, 3, 4], array_map(fn ($race): int => $race->context->raceId, $races));
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($result): int => $result->bikeNumber, $races[0]->results));
        $this->assertSame(4, count(array_merge(...iterator_to_array($first->chunks($fold, 3), false))), 'Repeated replay must not mutate the snapshot.');
    }

    public function test_production_target_contract_comes_from_the_fixed_bt01_manifest(): void
    {
        $manifest = new Bt01SourceManifest(new CanonicalHasher);

        $this->assertSame(
            [24868, 25561, 25624, 25273],
            array_map(fn (int $year): int => $manifest->forYear($year)->expectedRaceCount, [2022, 2023, 2024, 2025]),
        );
    }

    public function test_manifest_same_length_mutation_fails_closed(): void
    {
        $snapshot = $this->build($this->rows());
        $root = dirname($snapshot->partitionPath(2022));
        $manifestPath = $root.'/manifest.json';
        $contents = file_get_contents($manifestPath);
        $this->assertIsString($contents);
        $offset = strpos($contents, '2022.jsonl');
        $this->assertNotFalse($offset);
        $contents[$offset] = 'X';
        file_put_contents($manifestPath, $contents);

        $this->expectException(RuntimeException::class);
        Bt02OutcomeContextSnapshotArtifact::open($root, 'test/bt02/outcome-context');
    }

    #[DataProvider('corruptions')]
    public function test_partition_corruption_fails_closed(callable $corrupt): void
    {
        $snapshot = $this->build($this->rows());
        $corrupt($snapshot->partitionPath(2023));

        $this->expectException(RuntimeException::class);
        iterator_to_array($snapshot->chunks(
            new FoldDefinitionDto('Y2023', 1, null, null, new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31')),
            2,
        ));
    }

    /** @return iterable<string, array{callable(string): void}> */
    public static function corruptions(): iterable
    {
        yield 'same length byte mutation' => [static function (string $path): void {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            $offset = strpos($contents, 'CONFIRMED');
            self::assertNotFalse($offset);
            $contents[$offset] = 'X';
            file_put_contents($path, $contents);
        }];
        yield 'truncate' => [static function (string $path): void {
            $handle = fopen($path, 'c+b');
            self::assertIsResource($handle);
            ftruncate($handle, filesize($path) - 1);
            fclose($handle);
        }];
        yield 'append' => [static function (string $path): void {
            file_put_contents($path, "{}\n", FILE_APPEND);
        }];
    }

    public function test_duplicate_bike_and_non_fixed_year_fail_before_artifact_publication(): void
    {
        $duplicates = $this->rows();
        array_splice($duplicates, 1, 0, [$duplicates[0]]);
        $outOfOrder = $this->rows();
        $outOfOrder = [...array_slice($outOfOrder, 5, 5), ...array_slice($outOfOrder, 0, 5), ...array_slice($outOfOrder, 10)];
        foreach ([$duplicates, $outOfOrder, [new Bt02OutcomeContextSourceRowDto(99, '2026-01-01', null, null, 5, 'CONFIRMED', 'Ａ級予選', 1, 1, 'FINISHED')]] as $rows) {
            try {
                $this->build($rows);
                $this->fail('Expected invalid snapshot source to fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('BT-02 outcome snapshot', $exception->getMessage());
            }
            $published = array_values(array_filter(glob($this->directory.'/*') ?: [], fn (string $path): bool => is_dir($path) && ! str_contains(basename($path), '.building-')));
            $this->assertSame([], $published);
        }
    }

    public function test_fixed_target_count_mismatch_fails_closed(): void
    {
        $source = Mockery::mock(Bt02OutcomeContextSnapshotSourceRepository::class);
        $source->shouldReceive('rows')->once()->andReturn([]);
        $builder = new Bt02OutcomeContextSnapshotBuilder(
            new Bt01SourceManifest(new CanonicalHasher),
            $source,
            $this->directory,
            'test/bt02/outcome-context',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected 24868, got 0');
        $builder->build();
    }

    /** @param list<Bt02OutcomeContextSourceRowDto> $rows */
    private function build(array $rows): Bt02OutcomeContextSnapshotArtifact
    {
        $source = Mockery::mock(Bt02OutcomeContextSnapshotSourceRepository::class);
        $source->shouldReceive('rows')->once()->andReturn($rows);
        $snapshot = (new Bt02OutcomeContextSnapshotBuilder(
            $this->manifest(),
            $source,
            $this->directory,
            'test/bt02/outcome-context',
        ))->build();
        $this->assertInstanceOf(Bt02OutcomeContextSnapshotArtifact::class, $snapshot);

        return $snapshot;
    }

    private function manifest(): Bt01SourceManifest
    {
        return new Bt01SourceManifest(new CanonicalHasher, array_map(
            fn (int $year): SourceManifestEntryDto => new SourceManifestEntryDto(
                $year,
                $year,
                sprintf('00000000-0000-4000-8000-%012d', $year),
                "{$year}-01-01",
                "{$year}-12-31",
                1,
                5,
            ),
            [2022, 2023, 2024, 2025],
        ));
    }

    /** @return list<Bt02OutcomeContextSourceRowDto> */
    private function rows(): array
    {
        $rows = [];
        foreach ([2022, 2023, 2024, 2025] as $offset => $year) {
            $raceId = $offset + 1;
            foreach (range(1, 5) as $bike) {
                $rows[] = new Bt02OutcomeContextSourceRowDto(
                    $raceId,
                    "{$year}-06-01",
                    "{$year}-06-01 12:00:00+09:00",
                    null,
                    5,
                    'CONFIRMED',
                    'Ａ級予選',
                    $bike,
                    $bike,
                    'FINISHED',
                );
            }
        }

        return $rows;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $child = $path.'/'.$entry;
                is_dir($child) ? $this->remove($child) : @unlink($child);
            }
        }
        @rmdir($path);
    }
}
