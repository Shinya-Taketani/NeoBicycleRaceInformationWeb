<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e06ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e07DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e07PredictionDatasetBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03e07ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e07SourcePreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03e07TrainingDatasetBuilder;
use ReflectionProperty;
use Tests\TestCase;

final class Bt03e07ContainerWiringTest extends TestCase
{
    public function test_e07_services_share_one_dedicated_audit_singleton(): void
    {
        $audit = app(Bt03e07ReadOnlyQueryAudit::class);
        $this->assertSame($audit, app(Bt03e07ReadOnlyQueryAudit::class));
        foreach ([
            app(Bt03e07DevelopmentEvaluationService::class),
            app(Bt03e07SourcePreflightService::class),
            app(Bt03e07TrainingDatasetBuilder::class),
            app(Bt03e07PredictionDatasetBuilder::class),
        ] as $service) {
            $property = new ReflectionProperty($service, str_contains($service::class, 'Development') ? 'audit' : (str_contains($service::class, 'Training') ? 'queryAudit' : 'audit'));
            $this->assertSame($audit, $property->getValue($service));
        }

        $this->assertNotSame($audit, app(Bt03e04ReadOnlyQueryAudit::class));
        $this->assertNotSame($audit, app(Bt03e05ReadOnlyQueryAudit::class));
        $this->assertNotSame($audit, app(Bt03e06ReadOnlyQueryAudit::class));
    }

    public function test_plan_requires_no_source_bundle_or_database_access(): void
    {
        $this->artisan('keirin:backtest:bt03e07 --plan')
            ->expectsOutputToContain('BT-03E-07 PLAN')
            ->expectsOutputToContain('2026_access=FORBIDDEN')
            ->assertSuccessful();
    }
}
