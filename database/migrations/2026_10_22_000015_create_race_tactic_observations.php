<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Tactic observations require PostgreSQL or isolated SQLite.');
        }
        $counts = ['escape_count', 'sprint_count', 'overtake_count', 'mark_count', 'back_count', 'home_count', 'start_count'];
        Schema::create('race_tactic_observations', function (Blueprint $table) use ($counts): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('race_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('scraping_fetch_log_id')->constrained()->restrictOnDelete();
            $table->char('external_player_id', 6);
            $table->unsignedSmallInteger('bike_number');
            $table->date('race_date');
            $table->string('track_code', 10);
            $table->unsignedSmallInteger('race_number');
            foreach ($counts as $column) {
                $table->unsignedSmallInteger($column)->nullable();
            }
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('fetch_log_saved_at')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->string('timing_status', 50);
            $table->text('source_url');
            $table->text('raw_file_path');
            $table->char('raw_sha256', 64);
            $table->unsignedInteger('raw_bytes');
            $table->char('converted_sha256', 64);
            $table->char('source_metadata_hash', 64);
            $table->string('source_parser_version', 100);
            $table->string('parser_version', 100);
            $table->json('raw_fields');
            $table->string('aggregation_period_status', 30);
            $table->string('aggregation_basis_status', 30);
            $table->timestampTz('official_publication_at')->nullable();
            $table->string('use_class', 30);
            $table->json('metadata');
            $table->timestampTz('created_at');
            $table->unique(['scraping_fetch_log_id', 'race_entry_id', 'parser_version'], 'tactic_observations_capture_entry_version_unique');
            $table->index(['race_id', 'fetched_at'], 'tactic_observations_race_time_index');
            $table->index(['external_player_id', 'fetched_at'], 'tactic_observations_player_time_index');
        });
        $checks = ["race_date >= '2022-01-01' AND race_date < '2026-01-01'", 'bike_number BETWEEN 1 AND 9',
            'race_number BETWEEN 1 AND 12', 'raw_bytes BETWEEN 1 AND 4194304', 'length(external_player_id)=6',
            "aggregation_period_status='UNKNOWN'", "aggregation_basis_status='UNKNOWN'", 'official_publication_at IS NULL',
            "use_class='RETROSPECTIVE_ONLY'", "timing_status IN ('UNKNOWN','BEFORE_SAME_RAW_START','AT_OR_AFTER_SAME_RAW_START')"];
        foreach ($counts as $column) {
            $checks[] = "($column IS NULL OR $column BETWEEN 0 AND 9999)";
        }
        $checks[] = $driver === 'pgsql' ? "external_player_id ~ '^[0-9]{6}$'" : "external_player_id NOT GLOB '*[^0-9]*'";
        $check = implode(' AND ', $checks);
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE race_tactic_observations ADD CONSTRAINT tactic_observations_values_check CHECK (COALESCE(($check),false))");
            DB::unprepared("CREATE FUNCTION reject_tactic_observation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Tactic observations are append-only'; END; $$");
            DB::unprepared('CREATE TRIGGER tactic_observations_immutable BEFORE UPDATE OR DELETE ON race_tactic_observations FOR EACH ROW EXECUTE FUNCTION reject_tactic_observation_mutation()');
        } else {
            $names = [...$counts, 'race_date', 'bike_number', 'race_number', 'raw_bytes', 'external_player_id',
                'aggregation_period_status', 'aggregation_basis_status', 'official_publication_at', 'use_class', 'timing_status'];
            $check = preg_replace('/\b('.implode('|', $names).')\b/', 'NEW.$1', $check);
            DB::unprepared("CREATE TRIGGER tactic_observations_values BEFORE INSERT ON race_tactic_observations WHEN NOT COALESCE(($check),0) BEGIN SELECT RAISE(ABORT,'Invalid tactic observation'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER tactic_observations_no_{$operation} BEFORE {$operation} ON race_tactic_observations BEGIN SELECT RAISE(ABORT,'Tactic observations are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('race_tactic_observations')->exists()) {
            throw new RuntimeException('Cannot remove populated tactic observations. Review and export first.');
        }
        Schema::drop('race_tactic_observations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION reject_tactic_observation_mutation()');
        }
    }
};
