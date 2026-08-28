<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e02ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e04ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e05BaselineSourcePreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03e05DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e05EvaluationContextLoader;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyQueryAudit;
use ReflectionProperty;
use Tests\TestCase;

class Bt03e05ContainerWiringTest extends TestCase
{
    public function test_e05_audit_is_a_container_singleton_without_changing_existing_audit_singletons(): void
    {
        $first = app(Bt03e05ReadOnlyQueryAudit::class);
        $second = app(Bt03e05ReadOnlyQueryAudit::class);

        $this->assertSame($first, $second);
        $this->assertSame(spl_object_id($first), spl_object_id($second));
        $this->assertSame(app(Bt03eReadOnlyQueryAudit::class), app(Bt03eReadOnlyQueryAudit::class));
        $this->assertSame(app(Bt03e02ReadOnlyQueryAudit::class), app(Bt03e02ReadOnlyQueryAudit::class));
        $this->assertSame(app(Bt03e04ReadOnlyQueryAudit::class), app(Bt03e04ReadOnlyQueryAudit::class));
        $this->assertNotSame($first, app(Bt03e04ReadOnlyQueryAudit::class));
    }

    public function test_e05_production_services_receive_the_same_audit_instance(): void
    {
        $audit = app(Bt03e05ReadOnlyQueryAudit::class);
        $development = app(Bt03e05DevelopmentEvaluationService::class);
        $preflight = app(Bt03e05BaselineSourcePreflightService::class);
        $contexts = app(Bt03e05EvaluationContextLoader::class);

        $injected = [
            $this->injectedAudit($development, 'queryAudit'),
            $this->injectedAudit($preflight, 'audit'),
            $this->injectedAudit($contexts, 'audit'),
        ];

        foreach ($injected as $serviceAudit) {
            $this->assertSame($audit, $serviceAudit);
            $this->assertSame(spl_object_id($audit), spl_object_id($serviceAudit));
        }
        $this->assertCount(1, array_unique(array_map(spl_object_id(...), [$audit, ...$injected])));
    }

    public function test_started_and_frozen_state_is_shared_across_production_dependencies(): void
    {
        $audit = app(Bt03e05ReadOnlyQueryAudit::class);
        $preflightAudit = $this->injectedAudit(app(Bt03e05BaselineSourcePreflightService::class), 'audit');
        $contextAudit = $this->injectedAudit(app(Bt03e05EvaluationContextLoader::class), 'audit');

        $audit->start();
        try {
            $audit->recordDecoderContractFrozen();
            $audit->recordSourceBundleValidated();
            $preflightAudit->recordBaselineYear(2024);
            $preflightAudit->recordBaselineYear(2025);
            $contextAudit->recordSnapshotYear(2024);
            $contextAudit->recordSnapshotYear(2025);

            $result = $audit->finish();
            $this->assertSame(1, $result['baseline_feature_access'][2024]);
            $this->assertSame(1, $result['baseline_feature_access'][2025]);
            $this->assertSame(1, $result['snapshot_partition_access'][2024]);
            $this->assertSame(1, $result['snapshot_partition_access'][2025]);
            $this->assertTrue($result['decoder_contract_frozen']);
            $this->assertTrue($result['source_bundle_validated']);
        } finally {
            if ($audit->active()) {
                $audit->finish();
            }
        }
    }

    private function injectedAudit(object $service, string $property): Bt03e05ReadOnlyQueryAudit
    {
        $audit = (new ReflectionProperty($service, $property))->getValue($service);
        $this->assertInstanceOf(Bt03e05ReadOnlyQueryAudit::class, $audit);

        return $audit;
    }
}
