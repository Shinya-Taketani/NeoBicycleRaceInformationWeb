<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use Illuminate\Support\Facades\DB;

class Bt03EvaluationSourceRepository
{
    /** @return list<object> */
    public function folds(string $foldCode): array
    {
        return DB::table('backtest_folds')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->where('fold_code', $foldCode)
            ->orderBy('id')
            ->get()->all();
    }

    /** @return list<object> */
    public function signalSpecs(string $statCode): array
    {
        return DB::table('backtest_signal_specs')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->where('stat_code', $statCode)
            ->orderBy('id')
            ->get()->all();
    }

    /** @return list<object> */
    public function models(int $foldId, int $signalSpecId, string $cohortCode): array
    {
        return DB::table('backtest_models')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->where('backtest_fold_id', $foldId)
            ->where('backtest_signal_spec_id', $signalSpecId)
            ->where('cohort_code', $cohortCode)
            ->orderBy('label_code')
            ->orderBy('model_role')
            ->orderBy('id')
            ->get()->all();
    }

    /** @return list<object> */
    public function bins(int $foldId, int $signalSpecId, string $cohortCode): array
    {
        return DB::table('backtest_effect_bins')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->where('backtest_fold_id', $foldId)
            ->where('backtest_signal_spec_id', $signalSpecId)
            ->where('cohort_code', $cohortCode)
            ->orderBy('bin_index')
            ->orderBy('id')
            ->get()->all();
    }
}
