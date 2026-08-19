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
        Schema::create('backtest_bin_effect_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs');
            $table->foreignId('backtest_fold_id')->constrained('backtest_folds');
            $table->foreignId('backtest_signal_spec_id')->constrained('backtest_signal_specs');
            $table->foreignId('source_backtest_run_id')->constrained('backtest_runs');
            $table->foreignId('source_backtest_fold_id')->constrained('backtest_folds');
            $table->foreignId('source_backtest_signal_spec_id')->constrained('backtest_signal_specs');
            $table->string('cohort_code', 40);
            $table->string('status', 40);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('expected_training_bin_count');
            $table->char('source_boundaries_hash', 64);
            $table->unsignedInteger('bootstrap_iterations');
            $table->unsignedInteger('bootstrap_seed');
            $table->unsignedInteger('evaluation_row_count')->default(0);
            $table->unsignedInteger('evaluation_race_count')->default(0);
            $table->unsignedInteger('unseen_row_count')->default(0);
            $table->unsignedInteger('effect_count')->default(0);
            $table->unsignedBigInteger('spool_byte_count')->default(0);
            $table->unsignedInteger('maximum_bin_sample_count')->default(0);
            $table->unsignedInteger('maximum_bin_race_count')->default(0);
            $table->char('effect_manifest_hash', 64)->nullable();
            $table->text('error_summary')->nullable();
            $table->text('last_interruption_summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id', 'cohort_code'],
                'bt_bin_effect_scopes_run_fold_spec_cohort_unique',
            );
            $table->index(
                ['backtest_run_id', 'status'],
                'bt_bin_effect_scopes_run_status_index',
            );
            $table->index(
                ['source_backtest_run_id', 'source_backtest_fold_id', 'source_backtest_signal_spec_id', 'cohort_code'],
                'bt_bin_effect_scopes_source_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backtest_bin_effect_scopes ADD CONSTRAINT bt_bin_effect_scopes_status_check CHECK (status IN ('PENDING', 'RUNNING', 'SUCCEEDED', 'FAILED'))");
            DB::statement('ALTER TABLE backtest_bin_effect_scopes ADD CONSTRAINT bt_bin_effect_scopes_fixed_execution_check CHECK (expected_training_bin_count > 0 AND bootstrap_iterations = 2000 AND bootstrap_seed = 20260812)');
            DB::statement("ALTER TABLE backtest_bin_effect_scopes ADD CONSTRAINT bt_bin_effect_scopes_cohort_check CHECK (cohort_code IN ('STRICT', 'OPERATIONAL'))");
            DB::statement("ALTER TABLE backtest_bin_effect_scopes ADD CONSTRAINT bt_bin_effect_scopes_hash_check CHECK (source_boundaries_hash ~ '^[0-9a-f]{64}$' AND (effect_manifest_hash IS NULL OR effect_manifest_hash ~ '^[0-9a-f]{64}$'))");
            DB::statement("ALTER TABLE backtest_bin_effect_scopes ADD CONSTRAINT bt_bin_effect_scopes_lifecycle_check CHECK ((status = 'PENDING' AND attempt_count = 0 AND evaluation_row_count = 0 AND evaluation_race_count = 0 AND unseen_row_count = 0 AND effect_count = 0 AND spool_byte_count = 0 AND maximum_bin_sample_count = 0 AND maximum_bin_race_count = 0 AND effect_manifest_hash IS NULL AND error_summary IS NULL AND started_at IS NULL AND finished_at IS NULL) OR (status = 'RUNNING' AND attempt_count > 0 AND effect_count = 0 AND effect_manifest_hash IS NULL AND error_summary IS NULL AND started_at IS NOT NULL AND finished_at IS NULL) OR (status = 'SUCCEEDED' AND attempt_count > 0 AND effect_count > 0 AND effect_manifest_hash IS NOT NULL AND error_summary IS NULL AND started_at IS NOT NULL AND finished_at IS NOT NULL) OR (status = 'FAILED' AND attempt_count > 0 AND effect_count = 0 AND effect_manifest_hash IS NULL AND error_summary IS NOT NULL AND started_at IS NOT NULL AND finished_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_bin_effect_scopes');
    }
};
