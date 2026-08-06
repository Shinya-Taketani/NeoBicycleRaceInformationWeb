<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatisticFeatureMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_database_has_the_three_feature_tables_and_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('statistic_feature_runs', [
            'run_uuid',
            'stat_code',
            'calculation_version',
            'mode',
            'status',
            'parameters',
            'started_at',
            'finished_at',
        ]));
        $this->assertTrue(Schema::hasColumns('statistic_feature_run_items', [
            'feature_run_id',
            'race_id',
            'status',
            'feature_result_count',
        ]));
        $this->assertTrue(Schema::hasColumns('statistic_feature_results', [
            'feature_run_id',
            'race_entry_id',
            'features',
            'evidence',
            'input_hash',
            'raw_points',
            'confidence',
            'effective_points',
        ]));
    }

    public function test_down_removes_only_the_three_new_tables(): void
    {
        DB::table('players')->insert([
            'source' => 'migration-test',
            'external_player_id' => 'kept-player',
            'name' => 'Kept Player',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceColumns = $this->sourceColumns();
        $migration = $this->migration();

        try {
            $migration->down();

            $this->assertFalse(Schema::hasTable('statistic_feature_results'));
            $this->assertFalse(Schema::hasTable('statistic_feature_run_items'));
            $this->assertFalse(Schema::hasTable('statistic_feature_runs'));
            $this->assertTrue(Schema::hasTable('players'));
            $this->assertTrue(Schema::hasTable('races'));
            $this->assertTrue(Schema::hasTable('race_entries'));
            $this->assertSame(1, DB::table('players')->count());
            $this->assertSame($sourceColumns, $this->sourceColumns());
        } finally {
            $migration->up();
        }
    }

    public function test_legacy_reference_tables_and_rows_are_unchanged_by_up_and_down(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->createLegacyTables();
        DB::table('statistic_calculation_runs')->insert(['reference' => 'legacy-run']);
        DB::table('statistic_entry_results')->insert(['reference' => 'legacy-result']);
        DB::table('statistic_run_entry_results')->insert(['reference' => 'legacy-link']);

        try {
            $migration->up();
            $this->assertSame(1, DB::table('statistic_calculation_runs')->count());
            $this->assertSame(1, DB::table('statistic_entry_results')->count());
            $this->assertSame(1, DB::table('statistic_run_entry_results')->count());

            $migration->down();
            $this->assertSame('legacy-run', DB::table('statistic_calculation_runs')->value('reference'));
            $this->assertSame('legacy-result', DB::table('statistic_entry_results')->value('reference'));
            $this->assertSame('legacy-link', DB::table('statistic_run_entry_results')->value('reference'));
        } finally {
            Schema::dropIfExists('statistic_run_entry_results');
            Schema::dropIfExists('statistic_entry_results');
            Schema::dropIfExists('statistic_calculation_runs');
            if (! Schema::hasTable('statistic_feature_runs')) {
                $migration->up();
            }
        }
    }

    /** @return array<string, list<string>> */
    private function sourceColumns(): array
    {
        return [
            'races' => Schema::getColumnListing('races'),
            'race_entries' => Schema::getColumnListing('race_entries'),
            'race_results' => Schema::getColumnListing('race_results'),
            'players' => Schema::getColumnListing('players'),
            'scraping_fetch_logs' => Schema::getColumnListing('scraping_fetch_logs'),
        ];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_06_000005_create_statistic_feature_tables.php');
    }

    private function createLegacyTables(): void
    {
        foreach (['statistic_calculation_runs', 'statistic_entry_results', 'statistic_run_entry_results'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('reference');
            });
        }
    }
}
