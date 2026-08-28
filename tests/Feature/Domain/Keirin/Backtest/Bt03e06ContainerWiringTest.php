<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e06DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e06OutcomeEvaluationLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e06PredictionInputReconstructor;
use App\Domain\Keirin\Backtest\Services\Bt03e06ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e06SourcePreflightService;
use ReflectionProperty;
use Tests\TestCase;

class Bt03e06ContainerWiringTest extends TestCase
{
    public function test_all_e06_services_share_one_audit_distinct_from_historical_phases(): void
    {
        $audit = app(Bt03e06ReadOnlyQueryAudit::class);
        $this->assertSame($audit, app(Bt03e06ReadOnlyQueryAudit::class));
        $this->assertNotSame($audit, app(Bt03e05ReadOnlyQueryAudit::class));
        $this->assertNotSame($audit, app(Bt03e04ReadOnlyQueryAudit::class));

        foreach ([
            app(Bt03e06DevelopmentEvaluationService::class),
            app(Bt03e06SourcePreflightService::class),
            app(Bt03e06PredictionInputReconstructor::class),
            app(Bt03e06OutcomeEvaluationLoader::class),
        ] as $service) {
            $injected = (new ReflectionProperty($service, 'audit'))->getValue($service);
            $this->assertSame($audit, $injected);
            $this->assertSame(spl_object_id($audit), spl_object_id($injected));
        }
    }
}
