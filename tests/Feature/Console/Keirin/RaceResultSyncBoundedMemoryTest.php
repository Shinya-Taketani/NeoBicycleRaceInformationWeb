<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\Racetrack;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RaceResultSyncBoundedMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_every_race_across_chunk_boundaries_without_duplicates_or_gaps(): void
    {
        Storage::fake('local');
        Http::fake();
        $track = $this->track('56');
        $expectedIds = [];
        foreach (range(1, 205) as $sequence) {
            $expectedIds[] = (int) $this->race($track, $sequence)->id;
        }
        $candidateQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$candidateQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "races"') && str_contains($sql, 'join "racetracks"')) {
                $candidateQueries[] = $query->sql;
            }
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--sleep-ms' => '1',
        ])->expectsOutputToContain('success=0 skipped=205 failed=0 results=0 payouts=0')
            ->assertExitCode(0);

        $processedIds = $this->latestProcessedRaceIds();
        $this->assertSame($expectedIds, $processedIds);
        $this->assertCount(205, array_unique($processedIds));
        $this->assertCount(3, $candidateQueries);
        $this->assertStringContainsString('"races"."id" >', $candidateQueries[1]);
        $this->assertStringContainsString('"races"."id" >', $candidateQueries[2]);
        Http::assertNothingSent();
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_limit_is_applied_exactly_across_a_chunk_boundary(): void
    {
        Storage::fake('local');
        Http::fake();
        $track = $this->track('56');
        $expectedIds = [];
        foreach (range(1, 150) as $sequence) {
            $race = $this->race($track, $sequence);
            if ($sequence <= 101) {
                $expectedIds[] = (int) $race->id;
            }
        }

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--limit' => '101',
            '--sleep-ms' => '1',
        ])->expectsOutputToContain('success=0 skipped=101 failed=0 results=0 payouts=0')
            ->assertExitCode(0);

        $this->assertSame($expectedIds, $this->latestProcessedRaceIds());
        $this->assertSame(101, BatchRun::query()->latest('id')->firstOrFail()->skipped_count);
        Http::assertNothingSent();
    }

    public function test_id_track_number_date_result_available_and_force_filters_are_preserved(): void
    {
        Storage::fake('local');
        Http::fake();
        $track56 = $this->track('56');
        $track57 = $this->track('57');
        $raceA = $this->race($track56, 1, raceNumber: 1);
        $raceB = $this->race($track56, 2, raceNumber: 2);
        $raceC = $this->race($track57, 3, raceNumber: 1);
        $this->race($track56, 4, raceDate: '2026-01-02', raceNumber: 1);
        $hiddenRace = $this->race($track56, 5, raceNumber: 1, resultAvailable: false);

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--race-id' => (string) $raceB->id,
        ])->assertExitCode(0);
        $this->assertSame([(int) $raceB->id], $this->latestProcessedRaceIds());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--track-code' => '57',
        ])->assertExitCode(0);
        $this->assertSame([(int) $raceC->id], $this->latestProcessedRaceIds());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--race-number' => '2',
        ])->assertExitCode(0);
        $this->assertSame([(int) $raceB->id], $this->latestProcessedRaceIds());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--track-code' => '56',
            '--race-number' => '1',
        ])->assertExitCode(0);
        $this->assertSame([(int) $raceA->id], $this->latestProcessedRaceIds());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--track-code' => '56',
            '--race-number' => '1',
            '--force' => true,
        ])->assertExitCode(0);
        $this->assertSame([(int) $raceA->id, (int) $hiddenRace->id], $this->latestProcessedRaceIds());

        Http::assertNothingSent();
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_one_failure_at_the_chunk_boundary_does_not_stop_the_next_chunk(): void
    {
        Storage::fake('local');
        Http::fake();
        $track = $this->track('56');
        foreach (range(1, 99) as $sequence) {
            $this->race($track, $sequence);
        }
        $failedRace = $this->race($track, 100, raceType: 'S級予選');
        $nextChunkRaceA = $this->race($track, 101);
        $nextChunkRaceB = $this->race($track, 102);

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-01-01',
            '--sleep-ms' => '1',
        ])->expectsOutputToContain('success=0 skipped=101 failed=1 results=0 payouts=0')
            ->assertExitCode(1);

        $this->assertCount(102, $this->latestProcessedRaceIds());
        $this->assertDatabaseHas('batch_run_items', [
            'item_key' => 'race:'.$failedRace->id,
            'status' => 'FAILED',
        ]);
        foreach ([$nextChunkRaceA, $nextChunkRaceB] as $race) {
            $this->assertDatabaseHas('batch_run_items', [
                'item_key' => 'race:'.$race->id,
                'status' => 'SKIPPED_UNSUPPORTED_CATEGORY',
                'skip_reason' => 'UNSUPPORTED_RACE_CATEGORY',
            ]);
        }
        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame('PARTIALLY_FAILED', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
        Http::assertNothingSent();
    }

    private function track(string $code): Racetrack
    {
        return Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => $code,
            'name' => "合成競輪場{$code}",
        ]);
    }

    private function race(
        Racetrack $track,
        int $sequence,
        string $raceDate = '2026-01-01',
        int $raceNumber = 1,
        string $raceType = 'Ｌ級ガールズ予選',
        bool $resultAvailable = true,
    ): Race {
        return Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => sprintf('bounded:%s:%s:%04d', $track->external_track_id, str_replace('-', '', $raceDate), $sequence),
            'racetrack_id' => $track->id,
            'race_date' => $raceDate,
            'race_number' => $raceNumber,
            'race_type' => $raceType,
            'result_available' => $resultAvailable,
        ]);
    }

    /** @return list<int> */
    private function latestProcessedRaceIds(): array
    {
        $run = BatchRun::query()->latest('id')->firstOrFail();

        return BatchRunItem::query()
            ->where('batch_run_id', $run->id)
            ->where('item_type', 'RACE_RESULT')
            ->orderBy('id')
            ->pluck('item_key')
            ->map(fn (string $itemKey): int => (int) substr($itemKey, strlen('race:')))
            ->all();
    }
}
