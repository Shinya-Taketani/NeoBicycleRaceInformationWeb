<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e06ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e07ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e08DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e08OutcomeEvaluationLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e08PredictionDatasetBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03e08ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e08SourcePreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03e08TrainingDatasetBuilder;
use ReflectionProperty;
use Tests\TestCase;

final class Bt03e08ContainerWiringTest extends TestCase
{
    public function test_services_share_dedicated_audit_singleton_separate_from_frozen_engines(): void
    {
        $audit = app(Bt03e08ReadOnlyQueryAudit::class);
        $this->assertSame($audit, app(Bt03e08ReadOnlyQueryAudit::class));
        foreach ([app(Bt03e08DevelopmentEvaluationService::class), app(Bt03e08SourcePreflightService::class), app(Bt03e08TrainingDatasetBuilder::class), app(Bt03e08PredictionDatasetBuilder::class), app(Bt03e08OutcomeEvaluationLoader::class)] as $service) {
            $property = new ReflectionProperty($service, 'audit');
            $this->assertSame($audit, $property->getValue($service));
        }
        foreach ([Bt03e04ReadOnlyQueryAudit::class, Bt03e05ReadOnlyQueryAudit::class, Bt03e06ReadOnlyQueryAudit::class, Bt03e07ReadOnlyQueryAudit::class] as $class) {
            $this->assertNotSame($audit, app($class));
        }
    }

    public function test_plan_requires_no_source_or_database_access(): void
    {
        $this->artisan('keirin:backtest:bt03e08 --plan')->expectsOutputToContain('BT-03E-08 PLAN')->expectsOutputToContain('2026_access=FORBIDDEN')->assertSuccessful();
    }
}
