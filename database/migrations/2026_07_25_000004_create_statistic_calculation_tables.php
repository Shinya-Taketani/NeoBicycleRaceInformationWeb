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
        Schema::create('statistic_calculation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('stat_code', 20);
            $table->string('calculation_version', 80);
            $table->string('status', 40);
            $table->date('target_from')->nullable();
            $table->date('target_to')->nullable();
            $table->foreignId('target_race_id')->nullable()->constrained('races')->nullOnDelete();
            $table->jsonb('parameters');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('target_race_count')->default(0);
            $table->unsignedInteger('processed_race_count')->default(0);
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['stat_code', 'calculation_version', 'status'], 'stat_calc_runs_code_version_status_index');
            $table->index(['target_from', 'target_to'], 'stat_calc_runs_target_dates_index');
        });

        Schema::create('statistic_entry_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calculation_run_id')->constrained('statistic_calculation_runs')->restrictOnDelete();
            $table->string('stat_code', 20);
            $table->string('calculation_version', 80);
            $table->foreignId('race_id')->constrained('races')->restrictOnDelete();
            $table->foreignId('race_entry_id')->constrained('race_entries')->restrictOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->unsignedSmallInteger('bike_number');
            $table->decimal('race_score', 6, 2)->nullable();
            $table->unsignedSmallInteger('valid_score_count');
            $table->unsignedSmallInteger('missing_score_count');
            $table->unsignedSmallInteger('invalid_score_count');
            $table->unsignedSmallInteger('entrant_count');
            $table->unsignedSmallInteger('score_rank')->nullable();
            $table->unsignedSmallInteger('dense_rank')->nullable();
            $table->decimal('strength_percentile', 9, 8)->nullable();
            $table->decimal('race_average_score', 10, 4)->nullable();
            $table->decimal('race_max_score', 6, 2)->nullable();
            $table->decimal('difference_from_average', 10, 4)->nullable();
            $table->decimal('difference_from_max', 10, 4)->nullable();
            $table->decimal('race_standard_deviation', 10, 6)->nullable();
            $table->decimal('z_score', 12, 8)->nullable();
            $table->string('quality_status', 40);
            $table->string('acquisition_mode', 40);
            $table->jsonb('input_snapshot');
            $table->char('input_hash', 64);
            $table->string('source', 80);
            $table->timestampTz('source_fetched_at')->nullable();
            $table->decimal('raw_points', 12, 4)->nullable();
            $table->decimal('confidence', 9, 8)->nullable();
            $table->decimal('effective_points', 12, 4)->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(
                ['stat_code', 'calculation_version', 'race_entry_id', 'input_hash'],
                'stat_entry_result_snapshot_unique',
            );
            $table->index(['race_id', 'stat_code', 'calculation_version'], 'stat_entry_results_race_code_version_index');
            $table->index(['quality_status', 'acquisition_mode'], 'stat_entry_results_quality_mode_index');
            $table->index('input_hash');
        });

        Schema::create('statistic_run_entry_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calculation_run_id')->constrained('statistic_calculation_runs')->cascadeOnDelete();
            $table->foreignId('statistic_entry_result_id')->constrained('statistic_entry_results')->restrictOnDelete();
            $table->foreignId('race_id')->constrained('races')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(
                ['calculation_run_id', 'statistic_entry_result_id'],
                'stat_run_entry_result_unique',
            );
            $table->index(['calculation_run_id', 'race_id'], 'stat_run_entry_results_run_race_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE statistic_calculation_runs ADD CONSTRAINT statistic_calculation_runs_status_check CHECK (status IN ('RUNNING', 'SUCCEEDED', 'PARTIALLY_FAILED', 'FAILED', 'NO_TARGETS'))");
            DB::statement("ALTER TABLE statistic_entry_results ADD CONSTRAINT statistic_entry_results_quality_check CHECK (quality_status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT', 'HISTORICAL_SNAPSHOT', 'LEAKAGE_RISK', 'BLOCKED', 'ERROR'))");
            DB::statement("ALTER TABLE statistic_entry_results ADD CONSTRAINT statistic_entry_results_acquisition_check CHECK (acquisition_mode IN ('LIVE_PRE_RACE', 'HISTORICAL_RACE_CARD', 'UNKNOWN_ACQUISITION_MODE'))");
            DB::statement('ALTER TABLE statistic_entry_results ADD CONSTRAINT statistic_entry_results_bike_check CHECK (bike_number BETWEEN 1 AND 9)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statistic_run_entry_results');
        Schema::dropIfExists('statistic_entry_results');
        Schema::dropIfExists('statistic_calculation_runs');
    }
};
