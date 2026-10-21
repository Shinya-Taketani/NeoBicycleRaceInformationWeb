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
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Distance observation constraints require PostgreSQL or isolated SQLite.');
        }
        Schema::create('race_distance_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('scraping_fetch_log_id')->constrained()->restrictOnDelete();
            $table->date('race_date');
            $table->string('track_code', 10);
            $table->unsignedSmallInteger('race_number');
            $table->unsignedInteger('planned_distance_m')->nullable();
            $table->unsignedSmallInteger('planned_laps')->nullable();
            $table->string('distance_status', 30);
            $table->string('laps_status', 30);
            $table->unsignedInteger('actual_distance_m')->nullable();
            $table->unsignedSmallInteger('actual_laps')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('fetched_at')->nullable();
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
            $table->json('entrants');
            $table->json('metadata');
            $table->timestampTz('created_at');
            $table->unique(['scraping_fetch_log_id', 'parser_version'], 'distance_observations_capture_version_unique');
            $table->index(['race_id', 'fetched_at'], 'distance_observations_race_time_index');
        });
        $check = "actual_distance_m IS NULL AND actual_laps IS NULL
            AND ((distance_status='AVAILABLE' AND planned_distance_m BETWEEN 1 AND 10000)
                OR (distance_status='MISSING' AND planned_distance_m IS NULL))
            AND ((laps_status='AVAILABLE' AND planned_laps BETWEEN 1 AND 100)
                OR (laps_status='MISSING' AND planned_laps IS NULL))
            AND race_date >= '2022-01-01' AND race_date < '2026-01-01'
            AND timing_status IN ('BEFORE_SAME_RAW_START','AT_OR_AFTER_SAME_RAW_START','UNKNOWN')";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE race_distance_observations ADD CONSTRAINT distance_observations_values_check CHECK (COALESCE(($check),false))");
            DB::unprepared("CREATE FUNCTION reject_distance_observation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Distance observations are append-only'; END; $$");
            DB::unprepared('CREATE TRIGGER distance_observations_immutable BEFORE UPDATE OR DELETE ON race_distance_observations FOR EACH ROW EXECUTE FUNCTION reject_distance_observation_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            $newCheck = preg_replace('/\b(actual_distance_m|actual_laps|distance_status|planned_distance_m|laps_status|planned_laps|race_date|timing_status)\b/', 'NEW.$1', $check);
            DB::unprepared("CREATE TRIGGER distance_observations_values BEFORE INSERT ON race_distance_observations WHEN NOT COALESCE(($newCheck),0) BEGIN SELECT RAISE(ABORT,'Invalid distance observation'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER distance_observations_no_{$operation} BEFORE {$operation} ON race_distance_observations BEGIN SELECT RAISE(ABORT,'Distance observations are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('race_distance_observations')->exists()) {
            throw new RuntimeException('Cannot remove populated distance observations. Review and export first.');
        }
        Schema::drop('race_distance_observations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION reject_distance_observation_mutation()');
        }
    }
};
