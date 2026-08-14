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
use Tests\TestCase;

class Bt02OutcomeSnapshotSourceRepositoryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_live_source_is_read_once_excludes_2026_and_sealed_replay_is_immutable(): void
    {
        $raceIds = [];
        foreach ([2022, 2023, 2024, 2025, 2026] as $year) {
            $raceId = (int) DB::table('races')->insertGetId([
                'source' => 'fixture',
                'external_race_id' => "fixture:{$year}",
                'race_date' => "{$year}-06-01",
                'race_number' => 1,
                'race_type' => 'Ａ級予選',
                'entrant_count' => 5,
                'result_status' => 'CONFIRMED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $raceIds[$year] = $raceId;
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
        }
        $directory = sys_get_temp_dir().'/bt02-source-repository-'.bin2hex(random_bytes(8));
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        try {
            $snapshot = (new Bt02OutcomeContextSnapshotBuilder(
                $this->manifest(),
                new Bt02OutcomeContextSnapshotSourceRepository,
                $directory,
                'test/bt02/outcome-context',
            ))->build();
            $outcomeQueries = array_values(array_filter(
                $queries,
                fn (string $sql): bool => str_contains($sql, 'race_results') || preg_match('/\bfrom\s+"?races"?\b/', $sql) === 1,
            ));
            $this->assertCount(1, $outcomeQueries);
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));

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
