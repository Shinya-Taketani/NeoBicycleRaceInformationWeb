<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE statistic_feature_results DROP CONSTRAINT statistic_feature_results_status_check');
        DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_status_check CHECK (status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT', 'NO_HISTORY', 'PARTIAL_HISTORY', 'NOT_APPLICABLE'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('statistic_feature_results')->where('status', 'NOT_APPLICABLE')->exists()) {
            throw new RuntimeException('Cannot remove NOT_APPLICABLE while Batch03 statistic results use it.');
        }

        DB::statement('ALTER TABLE statistic_feature_results DROP CONSTRAINT statistic_feature_results_status_check');
        DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_status_check CHECK (status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT', 'NO_HISTORY', 'PARTIAL_HISTORY'))");
    }
};
