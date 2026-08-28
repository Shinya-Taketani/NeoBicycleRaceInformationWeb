<?php

namespace App\Providers;

use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Contracts\Bt03EvaluationSourceProvider;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Repositories\PgCopyFingerprintRunner;
use App\Domain\Keirin\Backtest\Services\Bt02EvaluationDatasetService;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use App\Domain\Keirin\Backtest\Services\Bt03e02ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationSourceLoader;
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
        $this->app->bind(Bt03EvaluationSourceProvider::class, Bt03EvaluationSourceLoader::class);
        $this->app->singleton(Bt02OutcomeContextSnapshotSession::class);
        $this->app->singleton(Bt03eReadOnlyQueryAudit::class);
        $this->app->singleton(Bt03e02ReadOnlyQueryAudit::class);
        $this->app->singleton(Bt03e04ReadOnlyQueryAudit::class);
        $this->app->singleton(Bt03e05ReadOnlyQueryAudit::class);
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
