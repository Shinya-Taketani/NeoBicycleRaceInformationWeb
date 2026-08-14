<?php

namespace App\Providers;

use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Repositories\PgCopyFingerprintRunner;
use App\Domain\Keirin\Backtest\Services\Bt02EvaluationDatasetService;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Bt02FingerprintRunner::class, PgCopyFingerprintRunner::class);
        $this->app->bind(Bt02EvaluationDataset::class, Bt02EvaluationDatasetService::class);
        $this->app->singleton(Bt02OutcomeContextSnapshotSession::class);
        $this->app->bind(EffectBinBoundaryProvider::class, ExternalSortEffectBinBoundaryProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
