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
        Schema::create('backtest_signal_specs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->string('stat_code', 40);
            $table->string('subject_type', 40);
            $table->string('analysis_role', 40);
            $table->string('primary_feature_code', 100);
            $table->string('primary_feature_path', 255)->nullable();
            $table->string('transform_code', 100);
            $table->string('strict_policy_version', 100);
            $table->string('operational_policy_version', 100);
            $this->jsonColumn($table, 'operational_allowed_quality_reasons');
            $table->string('source_manifest_version', 100);
            $table->char('source_manifest_hash', 64);
            $this->nullableJsonColumn($table, 'parameters');
            $table->timestampsTz();

            $table->unique(['backtest_run_id', 'stat_code'], 'bt_signal_specs_run_stat_unique');
        });

        Schema::create('backtest_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->foreignId('backtest_signal_spec_id')->constrained('backtest_signal_specs')->cascadeOnDelete();
            $table->string('model_role', 40);
            $table->string('label_code', 40);
            $table->string('cohort_code', 40);
            $table->date('training_from');
            $table->date('training_to');
            $table->date('inner_fit_from');
            $table->date('inner_fit_to');
            $table->date('inner_validation_from');
            $table->date('inner_validation_to');
            $this->jsonColumn($table, 'feature_names');
            $this->jsonColumn($table, 'scaler_mean');
            $this->jsonColumn($table, 'scaler_sd');
            $this->jsonColumn($table, 'lambda_candidates');
            $table->double('selected_lambda')->nullable();
            $table->double('intercept')->nullable();
            $this->jsonColumn($table, 'coefficients');
            $table->string('objective_version', 100);
            $table->string('optimizer_version', 100);
            $table->string('probability_semantics', 100);
            $table->string('convergence_status', 80);
            $table->unsignedSmallInteger('iterations')->default(0);
            $table->double('final_objective')->nullable();
            $table->char('model_hash', 64);
            $table->char('prediction_manifest_hash', 64)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['backtest_signal_spec_id', 'backtest_fold_id', 'label_code', 'cohort_code', 'model_role'],
                'bt_models_spec_fold_label_cohort_role_unique',
            );
            $table->index('model_hash');
        });

        Schema::create('backtest_signal_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->foreignId('backtest_signal_spec_id')->constrained('backtest_signal_specs')->cascadeOnDelete();
            $table->string('label_code', 40);
            $table->string('cohort_code', 40);
            $table->string('metric_code', 80);
            $table->double('baseline_value')->nullable();
            $table->double('incremental_value')->nullable();
            $table->double('delta_value')->nullable();
            $table->double('ci_lower')->nullable();
            $table->double('ci_upper')->nullable();
            $table->unsignedInteger('sample_count');
            $table->unsignedInteger('race_count');
            $table->unsignedInteger('bootstrap_iterations')->nullable();
            $table->unsignedInteger('bootstrap_seed')->nullable();
            $this->nullableJsonColumn($table, 'metadata');
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(
                ['backtest_fold_id', 'backtest_signal_spec_id', 'label_code', 'cohort_code', 'metric_code'],
                'bt_signal_metrics_fold_spec_label_cohort_metric_unique',
            );
        });

        Schema::create('backtest_effect_bins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds')->cascadeOnDelete();
            $table->foreignId('backtest_signal_spec_id')->constrained('backtest_signal_specs')->cascadeOnDelete();
            $table->string('cohort_code', 40);
            $table->unsignedSmallInteger('bin_index');
            $table->string('bin_kind', 40);
            $table->double('lower_bound')->nullable();
            $table->double('upper_bound')->nullable();
            $table->string('category_value', 255)->nullable();
            $table->unsignedInteger('training_sample_count');
            $table->char('boundaries_hash', 64);
            $this->nullableJsonColumn($table, 'metadata');
            $table->timestampsTz();

            $table->unique(
                ['backtest_fold_id', 'backtest_signal_spec_id', 'cohort_code', 'bin_index'],
                'bt_effect_bins_fold_spec_cohort_bin_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backtest_signal_specs ADD CONSTRAINT bt_signal_specs_role_check CHECK (analysis_role IN ('ENTRY_INCREMENTAL', 'RACE_STRATIFIER', 'DIAGNOSTIC_ONLY'))");
            DB::statement("ALTER TABLE backtest_models ADD CONSTRAINT bt_models_role_check CHECK (model_role IN ('BASELINE_MATCHED', 'INCREMENTAL'))");
            DB::statement('ALTER TABLE backtest_models ADD CONSTRAINT bt_models_date_check CHECK (training_from <= training_to AND inner_fit_from <= inner_fit_to AND inner_validation_from <= inner_validation_to)');
            DB::statement('ALTER TABLE backtest_models ADD CONSTRAINT bt_models_iterations_check CHECK (iterations <= 100)');
            DB::statement("ALTER TABLE backtest_effect_bins ADD CONSTRAINT bt_effect_bins_kind_check CHECK (bin_kind IN ('NUMERIC_RANGE', 'CATEGORY'))");
            DB::statement("ALTER TABLE backtest_effect_bins ADD CONSTRAINT bt_effect_bins_shape_check CHECK ((bin_kind = 'CATEGORY' AND category_value IS NOT NULL AND lower_bound IS NULL AND upper_bound IS NULL) OR (bin_kind = 'NUMERIC_RANGE' AND category_value IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_effect_bins');
        Schema::dropIfExists('backtest_signal_metrics');
        Schema::dropIfExists('backtest_models');
        Schema::dropIfExists('backtest_signal_specs');
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
