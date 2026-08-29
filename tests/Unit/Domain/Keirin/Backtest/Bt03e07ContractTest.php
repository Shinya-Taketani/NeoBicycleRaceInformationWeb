<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use PHPUnit\Framework\TestCase;

final class Bt03e07ContractTest extends TestCase
{
    public function test_p1_is_source_frozen_and_only_p2_p3_are_fitted(): void
    {
        $this->assertSame(['POSITION_2', 'POSITION_3'], Bt03e07Contract::POSITIONS);
        $this->assertStringContainsString('never fit', Bt03e07Contract::plan()['p1_freeze_rule']);
        $root = dirname(__DIR__, 5);
        $optimizer = file_get_contents($root.'/app/Domain/Keirin/Backtest/Calculators/Bt03e07FistaOptimizer.php');
        $objective = file_get_contents($root.'/app/Domain/Keirin/Backtest/Calculators/Bt03e07DirectPositionObjective.php');
        $this->assertIsString($optimizer);
        $this->assertIsString($objective);
        $this->assertStringNotContainsString("'POSITION_1'", $optimizer);
        $this->assertStringNotContainsString("'POSITION_1'", $objective);
    }

    public function test_fixed_grid_solver_and_gate_contracts_match_the_frozen_versions(): void
    {
        $this->assertSame([0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0], Bt03e07Contract::LAMBDA_GRID);
        $this->assertSame([1.0, 1e-1, 1e-2, 1e-3, 1e-4, 1e-5, 1e-6, 0.0], Bt03e07Contract::FIT_EXECUTION_ORDER);
        $this->assertSame(200, Bt03e07Contract::MAX_ITERATIONS);
        $this->assertSame(20260812, Bt03e07Contract::BOOTSTRAP_SEED);
        $this->assertSame(-0.0015, Bt03e07Contract::acceptanceGate()['non_inferiority']['primary_ci_lower_gt']);
    }
}
