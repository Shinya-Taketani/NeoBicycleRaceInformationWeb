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
        DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_status_check CHECK (status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT', 'NO_HISTORY', 'PARTIAL_HISTORY'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $count = DB::table('statistic_feature_results')
            ->whereIn('status', ['NO_HISTORY', 'PARTIAL_HISTORY'])
            ->count();
        if ($count > 0) {
            throw new RuntimeException('Cannot restore the original statistic result status constraint while Batch02 statuses exist.');
        }

        DB::statement('ALTER TABLE statistic_feature_results DROP CONSTRAINT statistic_feature_results_status_check');
        DB::statement("ALTER TABLE statistic_feature_results ADD CONSTRAINT statistic_feature_results_status_check CHECK (status IN ('VALID', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT'))");
    }
};
