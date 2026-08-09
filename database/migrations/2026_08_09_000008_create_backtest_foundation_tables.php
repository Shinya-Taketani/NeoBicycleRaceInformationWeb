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
        Schema::create('backtest_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('backtest_code', 40);
            $table->string('calculation_version', 100);
            $table->string('status', 40);
            $table->string('holdout_policy', 100);
            $table->string('source_manifest_version', 100);
            $table->char('source_manifest_hash', 64);
            $table->string('prediction_rule_version', 100);
            $this->jsonColumn($table, 'parameters');
            $table->unsignedInteger('target_race_count')->default(0);
            $table->unsignedInteger('predicted_race_count')->default(0);
            $table->unsignedInteger('excluded_race_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['backtest_code', 'status']);
            $table->index('source_manifest_hash');
        });

        Schema::create('backtest_folds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->string('fold_code', 40);
            $table->unsignedSmallInteger('sequence');
            $table->date('train_from')->nullable();
            $table->date('train_to')->nullable();
            $table->date('evaluation_from');
            $table->date('evaluation_to');
            $table->string('status', 40);
            $table->unsignedInteger('target_race_count')->default(0);
            $table->unsignedInteger('predicted_race_count')->default(0);
            $table->unsignedInteger('excluded_race_count')->default(0);
            $table->char('prediction_manifest_hash', 64)->nullable();
            $table->char('label_manifest_hash', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['backtest_run_id', 'fold_code']);
            $table->index(['sequence', 'evaluation_from', 'evaluation_to']);
        });

        Schema::create('backtest_feature_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->string('stat_code', 40);
            $table->unsignedBigInteger('feature_run_id');
            $table->uuid('feature_run_uuid');
            $table->string('calculation_version', 100);
            $table->date('target_from');
            $table->date('target_to');
            $table->unsignedInteger('expected_race_count');
            $table->unsignedInteger('expected_result_count');
            $table->unsignedInteger('verified_race_count');
            $table->unsignedInteger('verified_result_count');
            $table->char('source_manifest_hash', 64);
            $table->timestampTz('verified_at');
            $table->timestampsTz();

            $table->unique(['backtest_run_id', 'feature_run_id']);
            $table->index('feature_run_uuid');
        });

        Schema::create('backtest_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->unsignedSmallInteger('bike_number');
            $table->unsignedBigInteger('feature_run_id');
            $table->unsignedBigInteger('feature_result_id');
            $table->char('source_input_hash', 64);
            $table->string('prediction_rule_version', 100);
            $table->decimal('prediction_score', 8, 2);
            $table->unsignedSmallInteger('predicted_rank');
            $table->boolean('is_rank1_set');
            $table->boolean('is_top3_set');
            $table->char('prediction_hash', 64);
            $table->timestampTz('locked_at');
            $table->timestampsTz();

            $table->unique(['backtest_run_id', 'race_entry_id']);
            $table->index(['backtest_fold_id', 'race_id']);
            $table->index('prediction_hash');
        });

        Schema::create('backtest_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->string('cohort_code', 40);
            $table->string('metric_code', 80);
            $table->decimal('numerator', 18, 6)->nullable();
            $table->decimal('denominator', 18, 6)->nullable();
            $table->unsignedInteger('sample_count');
            $table->decimal('metric_value', 18, 10)->nullable();
            $this->nullableJsonColumn($table, 'metadata');
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(['backtest_fold_id', 'cohort_code', 'metric_code']);
        });

        Schema::create('backtest_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id')->nullable();
            $table->string('stage', 40);
            $table->string('reason_code', 100);
            $this->nullableJsonColumn($table, 'details');
            $table->timestampTz('created_at');

            $table->index(['backtest_fold_id', 'stage', 'reason_code'], 'backtest_exclusions_fold_stage_reason_index');
            $table->index(['race_id', 'race_entry_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backtest_runs ADD CONSTRAINT backtest_runs_status_check CHECK (status IN ('RUNNING', 'SUCCEEDED', 'PARTIALLY_SUCCEEDED', 'FAILED'))");
            DB::statement("ALTER TABLE backtest_folds ADD CONSTRAINT backtest_folds_status_check CHECK (status IN ('RUNNING', 'SUCCEEDED', 'PARTIALLY_SUCCEEDED', 'FAILED'))");
            DB::statement('ALTER TABLE backtest_predictions ADD CONSTRAINT backtest_predictions_rank_check CHECK (predicted_rank > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_exclusions');
        Schema::dropIfExists('backtest_metrics');
        Schema::dropIfExists('backtest_predictions');
        Schema::dropIfExists('backtest_feature_sources');
        Schema::dropIfExists('backtest_folds');
        Schema::dropIfExists('backtest_runs');
    }

    private function jsonColumn(Blueprint $table, string $name): void
    {
        DB::getDriverName() === 'pgsql' ? $table->jsonb($name) : $table->json($name);
    }

    private function nullableJsonColumn(Blueprint $table, string $name): void
    {
        DB::getDriverName() === 'pgsql' ? $table->jsonb($name)->nullable() : $table->json($name)->nullable();
    }
};
