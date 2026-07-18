<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Racetrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RaceResultRawTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_result_text_is_declared_as_text_and_stores_more_than_255_characters(): void
    {
        $rawResultText = str_repeat('長い結果JSON', 80);
        $race = $this->race();

        $result = RaceResult::query()->create([
            'race_id' => $race->id,
            'bike_number' => 1,
            'rank' => 1,
            'result_status' => 'FINISHED',
            'raw_result_text' => $rawResultText,
            'fetched_at' => now(),
        ]);

        $this->assertGreaterThan(255, mb_strlen($rawResultText));
        $this->assertSame($rawResultText, $result->refresh()->raw_result_text);
        $this->assertSame('text', $this->rawResultTextType());
    }

    public function test_down_refuses_to_narrow_the_column_when_oversized_data_exists(): void
    {
        $rawResultText = str_repeat('x', 256);
        $result = RaceResult::query()->create([
            'race_id' => $this->race()->id,
            'bike_number' => 1,
            'rank' => 1,
            'result_status' => 'FINISHED',
            'raw_result_text' => $rawResultText,
            'fetched_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_07_19_000003_change_race_results_raw_result_text_to_text.php');

        try {
            $migration->down();
            $this->fail('The migration down method accepted data longer than 255 characters.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 row(s) exceed 255 characters', $exception->getMessage());
        }

        $this->assertSame($rawResultText, $result->refresh()->raw_result_text);
        $this->assertSame('text', $this->rawResultTextType());
    }

    private function rawResultTextType(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $column = DB::selectOne(<<<'SQL'
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'race_results'
                  AND column_name = 'raw_result_text'
                SQL);

            return strtolower((string) ($column->data_type ?? ''));
        }

        foreach (DB::select('PRAGMA table_info(race_results)') as $column) {
            if (($column->name ?? null) === 'raw_result_text') {
                return strtolower((string) ($column->type ?? ''));
            }
        }

        return '';
    }

    private function race(): Race
    {
        $track = Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => 'migration-test',
            'name' => 'Migration test track',
        ]);

        return Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => 'migration-test-race',
            'racetrack_id' => $track->id,
            'race_date' => '2026-07-19',
            'race_number' => 1,
        ]);
    }
}
