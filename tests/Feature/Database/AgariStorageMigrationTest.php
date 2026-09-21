<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Racetrack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AgariStorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_indexes_and_restrict_foreign_keys_are_present(): void
    {
        $this->assertTrue(Schema::hasColumns('race_results', ['agari_time_seconds', 'agari_raw_text', 'agari_status']));
        $this->assertTrue(Schema::hasColumns('race_result_agari_observations', [
            'race_id', 'race_result_import_id', 'race_entry_id', 'player_id', 'bike_number', 'result_status',
            'agari_time_seconds', 'agari_raw_text', 'agari_status', 'fetched_at', 'source_url', 'parser_version', 'created_at', 'metadata',
        ]));
        $indexes = collect(Schema::getIndexes('race_result_agari_observations'))->keyBy('name');
        $this->assertTrue($indexes['agari_observations_import_bike_unique']['unique']);
        $this->assertSame(['race_result_import_id', 'bike_number'], $indexes['agari_observations_import_bike_unique']['columns']);
        $this->assertTrue($indexes->has('agari_observations_race_bike_index'));
        $keys = Schema::getForeignKeys('race_result_agari_observations');
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertContains($key['foreign_table'], ['races', 'race_result_imports']);
            $this->assertSame('restrict', $key['on_delete']);
        }
        $type = Schema::getColumnType('race_results', 'agari_time_seconds');
        $this->assertSame(DB::getDriverName() === 'pgsql' ? 'numeric' : 'text', $type);
        if (DB::getDriverName() === 'pgsql') {
            $column = DB::selectOne("SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='race_results' AND column_name='agari_time_seconds'");
            $this->assertNull($column->numeric_precision);
            $this->assertNull($column->numeric_scale);
        }
    }

    public function test_empty_down_up_preserves_all_existing_source_rows(): void
    {
        $row = $this->makeResult();
        $before = $row->getAttributes();
        $before = array_diff_key($before, array_flip(['agari_raw_text', 'agari_time_seconds', 'agari_status']));
        $migration = require database_path('migrations/2026_09_21_000013_add_agari_storage.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('race_result_agari_observations'));
            $this->assertFalse(Schema::hasColumn('race_results', 'agari_status'));
            $this->assertSame($before, $row->refresh()->getAttributes());
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('race_result_agari_observations'));
        $this->assertNull($row->refresh()->agari_status);
    }

    public function test_exact_decimals_and_down_refusal_protect_current_data(): void
    {
        $row = $this->makeResult();
        foreach (['11.12345678901234567890123456789', '0.000000000000000000000000001'] as $value) {
            $row->update(['agari_time_seconds' => $value, 'agari_raw_text' => $value, 'agari_status' => 'VALID']);
            $this->assertSame($value, $row->refresh()->agari_time_seconds);
        }
        $before = $row->getAttributes();
        $migration = require database_path('migrations/2026_09_21_000013_add_agari_storage.php');
        try {
            $migration->down();
            $this->fail('Populated down must fail without dropping data.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Cannot remove agari storage', $error->getMessage());
        }
        $this->assertSame($before, $row->refresh()->getAttributes());
        $this->assertTrue(Schema::hasTable('race_result_agari_observations'));
    }

    public function test_postgres_numeric_checks_reject_inconsistent_values_when_applicable(): void
    {
        $row = $this->makeResult();
        $this->assertNull($row->agari_status);
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite tests the portable exact-string representation; numeric CHECKs are PostgreSQL-specific.
            $this->assertSame('text', Schema::getColumnType('race_results', 'agari_time_seconds'));

            return;
        }
        foreach ([['0', 'VALID'], ['-1', 'VALID'], ['NaN', 'VALID'], ['Infinity', 'VALID'], ['11.5', 'MISSING'], ['11.5', null], ['11.5', 'UNKNOWN']] as [$value, $status]) {
            try {
                DB::transaction(fn () => $row->update(['agari_time_seconds' => $value, 'agari_raw_text' => $value, 'agari_status' => $status]));
                $this->fail('Invalid numeric/status combination was accepted.');
            } catch (QueryException) {
                $this->assertNull($row->refresh()->agari_status);
            }
        }
    }

    private function makeResult(): RaceResult
    {
        $track = Racetrack::query()->create(['source' => 'keirin_jp', 'external_track_id' => '56', 'name' => 'Synthetic']);
        $race = Race::query()->create(['source' => 'keirin_jp', 'external_race_id' => 'migration-agari',
            'racetrack_id' => $track->id, 'race_date' => '2025-02-01', 'race_number' => 1]);

        return RaceResult::query()->create(['race_id' => $race->id, 'bike_number' => 1, 'rank' => 1,
            'result_status' => 'FINISHED', 'raw_result_text' => 'preserved', 'fetched_at' => now()])->refresh();
    }
}
