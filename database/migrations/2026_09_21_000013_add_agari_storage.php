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
        Schema::table('race_results', function (Blueprint $table): void {
            $table->text('agari_time_seconds')->nullable();
            $table->text('agari_raw_text')->nullable();
            $table->string('agari_status', 40)->nullable();
        });
        Schema::create('race_result_agari_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('race_result_import_id')->constrained()->restrictOnDelete();
            // Historical identity values must survive physical corrections to live entry/player rows.
            $table->unsignedBigInteger('race_entry_id')->nullable()->index();
            $table->unsignedBigInteger('player_id')->nullable()->index();
            $table->string('external_player_id', 32)->nullable();
            $table->unsignedSmallInteger('bike_number');
            $table->string('result_status', 40);
            $table->text('agari_time_seconds')->nullable();
            $table->text('agari_raw_text')->nullable();
            $table->string('agari_status', 40);
            $table->text('source_url');
            $table->timestampTz('fetched_at')->nullable();
            $table->string('parser_version', 80);
            $table->json('metadata');
            $table->timestampTz('created_at');
            $table->unique(['race_result_import_id', 'bike_number'], 'agari_observations_import_bike_unique');
            $table->index(['race_id', 'bike_number'], 'agari_observations_race_bike_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Unconstrained NUMERIC preserves all source decimal digits; SQLite uses exact TEXT, not REAL affinity.
            foreach (['race_results', 'race_result_agari_observations'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN agari_time_seconds TYPE NUMERIC USING agari_time_seconds::numeric");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_agari_check CHECK (COALESCE((
                    (agari_status IS NULL AND agari_time_seconds IS NULL AND agari_raw_text IS NULL)
                    OR (agari_status IN ('MISSING', 'INVALID_FORMAT') AND agari_time_seconds IS NULL)
                    OR (agari_status IN ('VALID', 'OBSERVED_ABNORMAL_RESULT') AND agari_raw_text IS NOT NULL
                        AND agari_time_seconds IS NOT NULL AND agari_time_seconds > 0
                        AND agari_time_seconds::text NOT IN ('NaN', 'Infinity', '-Infinity'))), false))");
            }
            DB::statement('ALTER TABLE race_result_agari_observations ADD CONSTRAINT agari_observations_bike_check CHECK (bike_number BETWEEN 1 AND 9)');
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_agari_observation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Agari observations are append-only'; END; $$");
            DB::unprepared('CREATE TRIGGER agari_observations_immutable BEFORE UPDATE OR DELETE ON race_result_agari_observations FOR EACH ROW EXECUTE FUNCTION reject_agari_observation_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER agari_observations_no_{$operation} BEFORE {$operation} ON race_result_agari_observations BEGIN SELECT RAISE(ABORT, 'Agari observations are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('race_result_agari_observations')->exists()
            || DB::table('race_results')->whereNotNull('agari_status')->orWhereNotNull('agari_raw_text')->orWhereNotNull('agari_time_seconds')->exists()) {
            throw new RuntimeException('Cannot remove agari storage containing observations or current values. Export and review first.');
        }
        Schema::drop('race_result_agari_observations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION reject_agari_observation_mutation()');
            DB::statement('ALTER TABLE race_results DROP CONSTRAINT race_results_agari_check');
        }
        Schema::table('race_results', function (Blueprint $table): void {
            $table->dropColumn(['agari_time_seconds', 'agari_raw_text', 'agari_status']);
        });
    }
};
