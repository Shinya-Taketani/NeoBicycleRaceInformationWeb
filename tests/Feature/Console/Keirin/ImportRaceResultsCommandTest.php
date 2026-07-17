<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\Race;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportRaceResultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_schedule_import_is_idempotent(): void
    {
        $fixture = base_path('tests/Fixtures/Keirin/race_schedule_2026_07.html');

        $this->artisan('keirin:races:import-results', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--raw-file' => $fixture,
        ])->assertExitCode(0);

        $firstCount = Race::query()->count();

        $this->artisan('keirin:races:import-results', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--raw-file' => $fixture,
        ])->assertExitCode(0);

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount, Race::query()->count());
    }
}
