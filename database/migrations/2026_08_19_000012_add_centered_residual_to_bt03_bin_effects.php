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
        Schema::table('backtest_bin_effects', function (Blueprint $table): void {
            $table->double('overall_baseline_residual_mean')->nullable()->after('baseline_residual_ci_upper');
            $table->double('centered_baseline_residual_mean')->nullable()->after('overall_baseline_residual_mean');
            $table->double('centered_baseline_residual_ci_lower')->nullable()->after('centered_baseline_residual_mean');
            $table->double('centered_baseline_residual_ci_upper')->nullable()->after('centered_baseline_residual_ci_lower');
            $table->string('centered_ci_status', 40)->after('centered_baseline_residual_ci_upper');
            $table->unsignedInteger('centered_bootstrap_valid_iterations')->after('centered_ci_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_centered_status_check CHECK (centered_ci_status IN ('AVAILABLE', 'SPARSE_BOOTSTRAP_UNSUPPORTED', 'NO_EVALUATION_ROWS'))");
            DB::statement('ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_centered_iterations_check CHECK (overall_baseline_residual_mean IS NOT NULL AND centered_bootstrap_valid_iterations >= 0 AND centered_bootstrap_valid_iterations <= bootstrap_iterations)');
            DB::statement("ALTER TABLE backtest_bin_effects ADD CONSTRAINT bt_bin_effects_centered_presence_check CHECK (centered_ci_status NOT IN ('AVAILABLE', 'SPARSE_BOOTSTRAP_UNSUPPORTED', 'NO_EVALUATION_ROWS') OR (evaluation_status = 'NO_EVALUATION_ROWS' AND centered_ci_status = 'NO_EVALUATION_ROWS' AND centered_baseline_residual_mean IS NULL AND centered_baseline_residual_ci_lower IS NULL AND centered_baseline_residual_ci_upper IS NULL AND centered_bootstrap_valid_iterations = 0) OR (evaluation_status = 'OBSERVED' AND centered_ci_status = 'AVAILABLE' AND centered_baseline_residual_mean IS NOT NULL AND centered_baseline_residual_ci_lower IS NOT NULL AND centered_baseline_residual_ci_upper IS NOT NULL AND centered_baseline_residual_ci_lower <= centered_baseline_residual_ci_upper AND centered_bootstrap_valid_iterations = bootstrap_iterations) OR (evaluation_status = 'OBSERVED' AND centered_ci_status = 'SPARSE_BOOTSTRAP_UNSUPPORTED' AND centered_baseline_residual_mean IS NOT NULL AND centered_baseline_residual_ci_lower IS NULL AND centered_baseline_residual_ci_upper IS NULL AND centered_bootstrap_valid_iterations < bootstrap_iterations))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE backtest_bin_effects DROP CONSTRAINT IF EXISTS bt_bin_effects_centered_presence_check');
            DB::statement('ALTER TABLE backtest_bin_effects DROP CONSTRAINT IF EXISTS bt_bin_effects_centered_iterations_check');
            DB::statement('ALTER TABLE backtest_bin_effects DROP CONSTRAINT IF EXISTS bt_bin_effects_centered_status_check');
        }

        Schema::table('backtest_bin_effects', function (Blueprint $table): void {
            $table->dropColumn([
                'overall_baseline_residual_mean',
                'centered_baseline_residual_mean',
                'centered_baseline_residual_ci_lower',
                'centered_baseline_residual_ci_upper',
                'centered_ci_status',
                'centered_bootstrap_valid_iterations',
            ]);
        });
    }
};
