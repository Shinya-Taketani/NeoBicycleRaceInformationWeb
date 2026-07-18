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
