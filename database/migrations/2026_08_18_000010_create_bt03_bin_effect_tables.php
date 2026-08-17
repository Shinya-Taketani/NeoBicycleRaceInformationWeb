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
        Schema::create('backtest_bin_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs');
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds');
            $table->foreignId('backtest_signal_spec_id')->constrained('backtest_signal_specs');
            $table->foreignId('source_backtest_run_id')->constrained('backtest_runs');
            $table->foreignId('source_backtest_fold_id')->constrained('backtest_folds');
            $table->foreignId('source_baseline_model_id')->constrained('backtest_models');
            $table->foreignId('source_incremental_model_id')->constrained('backtest_models');
            $table->foreignId('source_backtest_effect_bin_id')->nullable()->constrained('backtest_effect_bins');
            $table->string('cohort_code', 40);
            $table->string('label_code', 40);
            $table->unsignedSmallInteger('bin_index');
            $table->string('bin_origin', 40);
            $table->string('bin_kind', 40);
            $table->double('lower_bound')->nullable();
            $table->double('upper_bound')->nullable();
            $table->string('category_value', 255)->nullable();
            $table->unsignedInteger('training_sample_count');
            $table->string('evaluation_status', 40);
            $table->unsignedInteger('evaluation_sample_count');
            $table->unsignedInteger('evaluation_race_count');
            $table->unsignedInteger('positive_count');
            $table->double('observed_rate')->nullable();
            $table->double('observed_rate_ci_lower')->nullable();
            $table->double('observed_rate_ci_upper')->nullable();
            $table->double('baseline_mean_probability')->nullable();
            $table->double('incremental_mean_probability')->nullable();
            $table->double('baseline_residual_mean')->nullable();
            $table->double('baseline_residual_ci_lower')->nullable();
            $table->double('baseline_residual_ci_upper')->nullable();
            $table->double('incremental_residual_mean')->nullable();
            $table->double('incremental_residual_ci_lower')->nullable();
            $table->double('incremental_residual_ci_upper')->nullable();
            $table->double('probability_shift_mean')->nullable();
            $table->double('probability_shift_ci_lower')->nullable();
            $table->double('probability_shift_ci_upper')->nullable();
            $table->double('log_loss_delta')->nullable();
            $table->double('log_loss_delta_ci_lower')->nullable();
            $table->double('log_loss_delta_ci_upper')->nullable();
            $table->double('brier_delta')->nullable();
            $table->double('brier_delta_ci_lower')->nullable();
            $table->double('brier_delta_ci_upper')->nullable();
            $table->unsignedInteger('bootstrap_iterations');
            $table->unsignedInteger('bootstrap_seed');
            $table->char('boundaries_hash', 64);
            $table->char('effect_hash', 64);
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(
                ['backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id', 'cohort_code', 'label_code', 'bin_index'],
                'bt_bin_effects_run_fold_spec_cohort_label_bin_unique',
            );
            $table->index(
                ['backtest_signal_spec_id', 'backtest_fold_id', 'cohort_code', 'label_code', 'bin_index'],
                'bt_bin_effects_query_index',
            );
            $table->index(
                ['source_backtest_run_id', 'source_backtest_fold_id'],
                'bt_bin_effects_source_index',
            );
            $table->index('effect_hash');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_status_check CHECK (evaluation_status IN ('OBSERVED', 'NO_EVALUATION_ROWS'))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_origin_check CHECK (bin_origin IN ('TRAINING_BIN', 'UNSEEN_CATEGORY'))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_kind_check CHECK (bin_kind IN ('NUMERIC_RANGE', 'CATEGORY'))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_origin_shape_check CHECK ((bin_origin = 'TRAINING_BIN' AND source_backtest_effect_bin_id IS NOT NULL AND bin_index > 0) OR (bin_origin = 'UNSEEN_CATEGORY' AND source_backtest_effect_bin_id IS NULL AND bin_index = 0 AND bin_kind = 'CATEGORY'))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_training_count_check CHECK ((bin_origin = 'TRAINING_BIN' AND training_sample_count > 0) OR (bin_origin = 'UNSEEN_CATEGORY' AND training_sample_count = 0))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_bin_shape_check CHECK ((bin_kind = 'CATEGORY' AND lower_bound IS NULL AND upper_bound IS NULL AND ((bin_origin = 'TRAINING_BIN' AND category_value IS NOT NULL) OR (bin_origin = 'UNSEEN_CATEGORY' AND category_value IS NULL))) OR (bin_kind = 'NUMERIC_RANGE' AND category_value IS NULL AND (lower_bound IS NULL OR upper_bound IS NULL OR lower_bound < upper_bound)))");
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_observation_check CHECK ((evaluation_status = 'OBSERVED' AND evaluation_sample_count > 0 AND evaluation_race_count > 0 AND evaluation_race_count <= evaluation_sample_count) OR (evaluation_status = 'NO_EVALUATION_ROWS' AND evaluation_sample_count = 0 AND evaluation_race_count = 0 AND positive_count = 0))");
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_positive_count_check CHECK (positive_count >= 0 AND positive_count <= evaluation_sample_count)');
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_bootstrap_check CHECK (bootstrap_iterations > 0 AND bootstrap_seed >= 0)');
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_probability_check CHECK ((observed_rate IS NULL OR observed_rate BETWEEN 0 AND 1) AND (observed_rate_ci_lower IS NULL OR observed_rate_ci_lower BETWEEN 0 AND 1) AND (observed_rate_ci_upper IS NULL OR observed_rate_ci_upper BETWEEN 0 AND 1) AND (baseline_mean_probability IS NULL OR baseline_mean_probability BETWEEN 0 AND 1) AND (incremental_mean_probability IS NULL OR incremental_mean_probability BETWEEN 0 AND 1))');
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_ci_order_check CHECK ((observed_rate_ci_lower IS NULL OR observed_rate_ci_upper IS NULL OR observed_rate_ci_lower <= observed_rate_ci_upper) AND (baseline_residual_ci_lower IS NULL OR baseline_residual_ci_upper IS NULL OR baseline_residual_ci_lower <= baseline_residual_ci_upper) AND (incremental_residual_ci_lower IS NULL OR incremental_residual_ci_upper IS NULL OR incremental_residual_ci_lower <= incremental_residual_ci_upper) AND (probability_shift_ci_lower IS NULL OR probability_shift_ci_upper IS NULL OR probability_shift_ci_lower <= probability_shift_ci_upper) AND (log_loss_delta_ci_lower IS NULL OR log_loss_delta_ci_upper IS NULL OR log_loss_delta_ci_lower <= log_loss_delta_ci_upper) AND (brier_delta_ci_lower IS NULL OR brier_delta_ci_upper IS NULL OR brier_delta_ci_lower <= brier_delta_ci_upper))');
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_value_presence_check CHECK ((evaluation_status = \'OBSERVED\' AND observed_rate IS NOT NULL AND observed_rate_ci_lower IS NOT NULL AND observed_rate_ci_upper IS NOT NULL AND baseline_mean_probability IS NOT NULL AND incremental_mean_probability IS NOT NULL AND baseline_residual_mean IS NOT NULL AND baseline_residual_ci_lower IS NOT NULL AND baseline_residual_ci_upper IS NOT NULL AND incremental_residual_mean IS NOT NULL AND incremental_residual_ci_lower IS NOT NULL AND incremental_residual_ci_upper IS NOT NULL AND probability_shift_mean IS NOT NULL AND probability_shift_ci_lower IS NOT NULL AND probability_shift_ci_upper IS NOT NULL AND log_loss_delta IS NOT NULL AND log_loss_delta_ci_lower IS NOT NULL AND log_loss_delta_ci_upper IS NOT NULL AND brier_delta IS NOT NULL AND brier_delta_ci_lower IS NOT NULL AND brier_delta_ci_upper IS NOT NULL) OR (evaluation_status = \'NO_EVALUATION_ROWS\' AND observed_rate IS NULL AND observed_rate_ci_lower IS NULL AND observed_rate_ci_upper IS NULL AND baseline_mean_probability IS NULL AND incremental_mean_probability IS NULL AND baseline_residual_mean IS NULL AND baseline_residual_ci_lower IS NULL AND baseline_residual_ci_upper IS NULL AND incremental_residual_mean IS NULL AND incremental_residual_ci_lower IS NULL AND incremental_residual_ci_upper IS NULL AND probability_shift_mean IS NULL AND probability_shift_ci_lower IS NULL AND probability_shift_ci_upper IS NULL AND log_loss_delta IS NULL AND log_loss_delta_ci_lower IS NULL AND log_loss_delta_ci_upper IS NULL AND brier_delta IS NULL AND brier_delta_ci_lower IS NULL AND brier_delta_ci_upper IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_bin_effects');
    }
};
