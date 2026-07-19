<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Services\BatchRunService;
use App\Domain\Keirin\Scraping\Services\RaceListSyncService;
use App\Domain\Keirin\Scraping\Services\RaceResultSyncService;
use App\Models\BatchRun;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BatchRunCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_query_failure_finishes_the_batch_run(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'race_days')) {
                throw new RuntimeException('synthetic race day query failure');
            }
        });

        try {
            $this->service()->sync($this->date(), $this->date());
            $this->fail('RuntimeException was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic race day query failure', $exception->getMessage());
        }

        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->failure_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_start_item_failure_finishes_the_batch_run(): void
    {
        $this->race();
        $this->partialMock(BatchRunService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('startItem')
                ->once()
                ->andThrow(new RuntimeException('synthetic start item failure'));
            $mock->shouldReceive('releaseLock')->once()->passthru();
        });

        try {
            $this->app->make(RaceResultSyncService::class)->sync($this->date(), $this->date());
            $this->fail('RuntimeException was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic start item failure', $exception->getMessage());
        }

        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->failure_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_meeting_day_reconciliation_failure_finishes_the_batch_run_and_preserves_race(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->raceDay();
        $meeting = RaceMeeting::query()->firstOrFail();
        $protectedDay = RaceDay::query()->create([
            'race_meeting_id' => $meeting->id,
            'external_race_day_id' => 'protected-extra-day',
            'race_date' => '2026-06-22',
            'day_number' => 2,
        ]);
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => '56:20260622:01',
            'race_day_id' => $protectedDay->id,
            'racetrack_id' => $meeting->racetrack_id,
            'race_date' => '2026-06-22',
            'race_number' => 1,
        ]);
        Http::fake([
            '*' => Http::response(
                (string) file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/race-sync-racelist.html')),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = $this->service()->sync($this->date(), $this->date(), ['sleep_ms' => 1]);

        $this->assertSame(1, $result['failed']);
        $run = $result['batch_run']->refresh();
        $this->assertNotSame('RUNNING', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('2026-06-22', $protectedDay->refresh()->race_date->format('Y-m-d'));
        $this->assertDatabaseHas('races', ['id' => $race->id, 'race_day_id' => $protectedDay->id]);
        $this->assertSame(2, RaceDay::query()->where('race_meeting_id', $meeting->id)->count());
    }

    private function service(): RaceListSyncService
    {
        return $this->app->make(RaceListSyncService::class);
    }

    private function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-16');
    }

    private function raceDay(): void
    {
        $track = Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => '56',
            'name' => 'synthetic track',
        ]);
        $meeting = RaceMeeting::query()->create([
            'source' => 'keirin_jp',
            'external_meeting_id' => 'batch-completion-test',
            'racetrack_id' => $track->id,
            'meeting_name' => 'synthetic meeting',
            'starts_on' => '2026-06-16',
            'ends_on' => '2026-06-16',
            'duration_days' => 1,
            'encrypted_parameter' => 'synthetic',
        ]);
        RaceDay::query()->create([
            'race_meeting_id' => $meeting->id,
            'external_race_day_id' => 'batch-completion-day',
            'race_date' => '2026-06-16',
            'day_number' => 1,
        ]);
    }

    private function race(): void
    {
        $this->raceDay();
        $day = RaceDay::query()->firstOrFail();
        $meeting = RaceMeeting::query()->firstOrFail();
        Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => '56:20260616:01',
            'race_day_id' => $day->id,
            'racetrack_id' => $meeting->racetrack_id,
            'race_date' => '2026-06-16',
            'race_number' => 1,
            'race_type' => 'S級予選',
            'entrant_count' => 7,
            'encrypted_parameter' => 'synthetic',
            'result_available' => true,
        ]);
    }
}
