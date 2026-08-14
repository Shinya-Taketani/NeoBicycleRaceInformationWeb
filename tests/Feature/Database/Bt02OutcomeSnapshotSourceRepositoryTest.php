<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Repositories\Bt02OutcomeContextSnapshotSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotBuilder;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class Bt02OutcomeSnapshotSourceRepositoryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_fixed_stat01_race_universe_excludes_extra_live_races_and_sealed_replay_is_immutable(): void
    {
        $manifest = $this->manifest();
        $this->insertFeatureRuns($manifest);
        $raceIds = [];
        foreach ([2022, 2023, 2024, 2025, 2026] as $year) {
            $raceId = $this->insertRace($year, "fixture:{$year}");
            $raceIds[$year] = $raceId;
            if ($year <= 2025) {
                $this->insertFeatureResults($manifest->forYear($year), $raceId);
            }
        }
        $extraLiveRaceId = $this->insertRace(2023, 'fixture:2023:extra');
        $directory = sys_get_temp_dir().'/bt02-source-repository-'.bin2hex(random_bytes(8));
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        try {
            $snapshot = (new Bt02OutcomeContextSnapshotBuilder(
                $manifest,
                new Bt02OutcomeContextSnapshotSourceRepository,
                $directory,
                'test/bt02/outcome-context',
            ))->build();
            $outcomeQueries = array_values(array_filter(
                $queries,
                fn (string $sql): bool => str_contains($sql, 'race_results') || preg_match('/\bfrom\s+"?races"?\b/', $sql) === 1,
            ));
            $this->assertCount(4, $outcomeQueries, 'The fixed universe must use one bounded query per manifest year.');
            foreach ($outcomeQueries as $query) {
                $this->assertStringContainsString('statistic_feature_results', $query);
            }
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));

            $allRaces = array_merge(...iterator_to_array($snapshot->chunks(
                new FoldDefinitionDto('ALL', 1, null, null, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2025-12-31')),
                200,
            ), false));
            $this->assertSame(
                array_values(array_intersect_key($raceIds, array_flip([2022, 2023, 2024, 2025]))),
                array_map(fn ($race): int => $race->context->raceId, $allRaces),
            );
            $this->assertNotContains($extraLiveRaceId, array_map(fn ($race): int => $race->context->raceId, $allRaces));
            $this->assertNotContains($raceIds[2026], array_map(fn ($race): int => $race->context->raceId, $allRaces));

            DB::table('race_results')->where('race_id', $raceIds[2023])->where('bike_number', 1)->update(['rank' => 2]);
            $races = array_merge(...iterator_to_array($snapshot->chunks(
                new FoldDefinitionDto('Y2023', 1, null, null, new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31')),
                200,
            ), false));
            $this->assertCount(1, $races);
            $this->assertSame(1, $races[0]->results[0]->rank, 'Replay must retain the sealed result instead of the changed live row.');
            $this->assertSame([2022, 2023, 2024, 2025], array_column($snapshot->auditParameters()['outcome_snapshot_partitions'], 'year'));
        } finally {
            $this->remove($directory);
        }
    }

    public function test_missing_fixed_stat01_race_fails_closed_with_source_identity(): void
    {
        $manifest = $this->manifest();
        $this->insertFeatureRuns($manifest);
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $raceId = $year === 2023 ? 999999 : $this->insertRace($year, "fixture:missing:{$year}");
            $this->insertFeatureResults($manifest->forYear($year), $raceId);
        }
        $directory = sys_get_temp_dir().'/bt02-source-missing-'.bin2hex(random_bytes(8));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('year 2023, feature_run_id 2023, race_id 999999');
            (new Bt02OutcomeContextSnapshotBuilder(
                $manifest,
                new Bt02OutcomeContextSnapshotSourceRepository,
                $directory,
                'test/bt02/outcome-context',
            ))->build();
        } finally {
            $this->remove($directory);
        }
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

    private function insertFeatureRuns(Bt01SourceManifest $manifest): void
    {
        foreach ($manifest->entries() as $entry) {
            DB::table('statistic_feature_runs')->insert([
                'id' => $entry->featureRunId,
                'run_uuid' => $entry->featureRunUuid,
                'stat_code' => Bt01SourceManifest::STAT_CODE,
                'calculation_version' => Bt01SourceManifest::CALCULATION_VERSION,
                'mode' => 'BACKFILL',
                'status' => 'PARTIALLY_SUCCEEDED',
                'history_from' => ($entry->year - 1).'-01-01',
                'target_from' => $entry->targetFrom,
                'target_to' => $entry->targetTo,
                'input_as_of_policy' => 'SALES_CLOSE_AT_THEN_SCHEDULED_START_AT',
                'parameters' => '{}',
                'target_race_count' => $entry->expectedRaceCount,
                'processed_race_count' => $entry->expectedRaceCount,
                'target_entry_count' => $entry->expectedResultCount,
                'error_count' => 0,
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertRace(int $year, string $externalRaceId): int
    {
        $raceId = (int) DB::table('races')->insertGetId([
            'source' => 'fixture',
            'external_race_id' => $externalRaceId,
            'race_date' => "{$year}-06-01",
            'race_number' => 1,
            'race_type' => 'Ａ級予選',
            'entrant_count' => 5,
            'result_status' => 'CONFIRMED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (range(1, 5) as $bike) {
            DB::table('race_results')->insert([
                'race_id' => $raceId,
                'bike_number' => $bike,
                'rank' => $bike,
                'result_status' => 'FINISHED',
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $raceId;
    }

    private function insertFeatureResults(SourceManifestEntryDto $source, int $raceId): void
    {
        foreach (range(1, 5) as $bike) {
            DB::table('statistic_feature_results')->insert([
                'feature_run_id' => $source->featureRunId,
                'stat_code' => Bt01SourceManifest::STAT_CODE,
                'calculation_version' => Bt01SourceManifest::CALCULATION_VERSION,
                'subject_type' => 'RACE_ENTRY',
                'subject_key' => "race:{$raceId}:bike:{$bike}",
                'race_id' => $raceId,
                'race_entry_id' => ($raceId * 10) + $bike,
                'bike_number' => $bike,
                'status' => 'VALID',
                'quality_status' => 'FULL',
                'acquisition_mode' => 'BACKFILL',
                'features' => '{}',
                'evidence' => '{}',
                'input_hash' => hash('sha256', "{$source->featureRunId}:{$raceId}:{$bike}"),
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
