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
        Schema::create('statistic_feature_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('stat_code', 40);
            $table->string('calculation_version', 100);
            $table->string('mode', 40);
            $table->string('status', 40);
            $table->date('history_from')->nullable();
            $table->date('target_from')->nullable();
            $table->date('target_to')->nullable();
            $table->unsignedBigInteger('target_race_id')->nullable();
            $table->string('input_as_of_policy', 100);
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('parameters');
            } else {
                $table->json('parameters');
            }
            $table->unsignedInteger('target_race_count')->default(0);
            $table->unsignedInteger('processed_race_count')->default(0);
            $table->unsignedInteger('target_entry_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['stat_code', 'status']);
            $table->index(['target_from', 'target_to']);
        });

        Schema::create('statistic_feature_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_run_id')->constrained('statistic_feature_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('race_id');
            $table->string('status', 40);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('feature_result_count')->default(0);
            $table->string('error_type', 180)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['feature_run_id', 'race_id']);
            $table->index(['status', 'race_id']);
        });

        Schema::create('statistic_feature_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_run_id')->constrained('statistic_feature_runs')->cascadeOnDelete();
            $table->string('stat_code', 40);
            $table->string('calculation_version', 100);
            $table->string('subject_type', 40);
            $table->string('subject_key', 255);
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id')->nullable();
            $table->unsignedBigInteger('player_id')->nullable();
            $table->unsignedBigInteger('opponent_player_id')->nullable();
            $table->unsignedSmallInteger('bike_number')->nullable();
            $table->string('status', 40);
            $table->string('quality_status', 40);
            $table->string('acquisition_mode', 40);
            $table->timestampTz('input_as_of')->nullable();
            $table->timestampTz('source_fetched_at')->nullable();
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('features');
                $table->jsonb('evidence');
            } else {
                $table->json('features');
                $table->json('evidence');
            }
            $table->char('input_hash', 64);
            $table->decimal('raw_points', 12, 6)->nullable();
            $table->decimal('confidence', 10, 8)->nullable();
            $table->decimal('effective_points', 12, 6)->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(['feature_run_id', 'stat_code', 'subject_type', 'subject_key'], 'stat_feature_results_subject_unique');
            $table->index(['race_id', 'stat_code']);
            $table->index(['race_entry_id', 'stat_code']);
            $table->index(['player_id', 'stat_code']);
            $table->index(['feature_run_id', 'status']);
            $table->index('input_hash');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE statistic_feature_runs ADD CONSTRAINT statistic_feature_runs_mode_check CHECK (mode IN ('BACKFILL'))");
            DB::statement("ALTER TABLE statistic_feature_runs ADD CONSTRAINT statistic_feature_runs_status_check CHECK (status IN ('RUNNING', 'SUCCEEDED', 'PARTIALLY_SUCCEEDED', 'FAILED'))");
            DB::statement("ALTER TABLE statistic_feature_run_items ADD CONSTRAINT statistic_feature_run_items_status_check CHECK (status IN ('PENDING', 'RUNNING', 'SUCCEEDED', 'PARTIAL', 'FAILED', 'SKIPPED'))");
            DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_status_check CHECK (status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT'))");
            DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_quality_check CHECK (quality_status IN ('FULL', 'PARTIAL', 'DEGRADED'))");
            DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_acquisition_check CHECK (acquisition_mode IN ('BACKFILL'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statistic_feature_results');
        Schema::dropIfExists('statistic_feature_run_items');
        Schema::dropIfExists('statistic_feature_runs');
    }
};
