<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use Illuminate\Support\Facades\DB;

class Bt03SourceArtifactRepository
{
    public function run(): ?object
    {
        return DB::table('backtest_runs')->where('id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)->first();
    }

    /** @return iterable<object> */
    public function folds(): iterable
    {
        return DB::table('backtest_folds')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->orderBy('sequence')
            ->orderBy('id')
            ->cursor();
    }

    /** @return iterable<object> */
    public function signalSpecs(): iterable
    {
        return DB::table('backtest_signal_specs')
            ->where('backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->orderBy('stat_code')
            ->orderBy('id')
            ->cursor();
    }

    /** @return iterable<object> */
    public function models(): iterable
    {
        return DB::table('backtest_models as models')
            ->join('backtest_folds as folds', 'folds.id', '=', 'models.backtest_fold_id')
            ->join('backtest_signal_specs as specs', 'specs.id', '=', 'models.backtest_signal_spec_id')
            ->where('models.backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->orderBy('folds.sequence')
            ->orderBy('specs.stat_code')
            ->orderBy('models.cohort_code')
            ->orderBy('models.label_code')
            ->orderBy('models.model_role')
            ->orderBy('models.id')
            ->select('models.*')
            ->cursor();
    }

    /** @return iterable<object> */
    public function metrics(): iterable
    {
        return DB::table('backtest_signal_metrics as metrics')
            ->join('backtest_folds as folds', 'folds.id', '=', 'metrics.backtest_fold_id')
            ->join('backtest_signal_specs as specs', 'specs.id', '=', 'metrics.backtest_signal_spec_id')
            ->where('metrics.backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->orderBy('folds.sequence')
            ->orderBy('specs.stat_code')
            ->orderBy('metrics.cohort_code')
            ->orderBy('metrics.label_code')
            ->orderBy('metrics.metric_code')
            ->orderBy('metrics.id')
            ->select('metrics.*')
            ->cursor();
    }

    /** @return iterable<object> */
    public function effectBins(): iterable
    {
        return DB::table('backtest_effect_bins as bins')
            ->join('backtest_folds as folds', 'folds.id', '=', 'bins.backtest_fold_id')
            ->join('backtest_signal_specs as specs', 'specs.id', '=', 'bins.backtest_signal_spec_id')
            ->where('bins.backtest_run_id', Bt03SourceManifest::SOURCE_BT02_RUN_ID)
            ->orderBy('folds.sequence')
            ->orderBy('specs.stat_code')
            ->orderBy('bins.cohort_code')
            ->orderBy('bins.bin_index')
            ->orderBy('bins.id')
            ->select('bins.*')
            ->cursor();
    }
}
