<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use PHPUnit\Framework\TestCase;

final class Bt03e08ContractTest extends TestCase
{
    public function test_contract_freezes_only_p3_redesign_and_all_numeric_constants(): void
    {
        $plan = Bt03e08Contract::plan();
        $this->assertSame('BT-03E-08-P1-Q2-FROZEN-WINNER-CONDITIONED-DIRECT-P3', $plan['contract']);
        $this->assertStringContainsString('never fit', $plan['p1_freeze_rule']);
        $this->assertStringContainsString('never fit', $plan['q2_freeze_rule']);
        $this->assertStringContainsString('ACTUAL_RANK2_REMAINS', $plan['p3_training_candidate_set']);
        $this->assertSame(['STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23', 'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42'], Bt03e08Contract::STAT_CODES);
        $this->assertNotContains('STAT-33', Bt03e08Contract::STAT_CODES);
        $this->assertNotContains('STAT-41', Bt03e08Contract::STAT_CODES);
        $this->assertSame([0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0], Bt03e08Contract::LAMBDA_GRID);
        $this->assertSame([1.0, 1e-1, 1e-2, 1e-3, 1e-4, 1e-5, 1e-6, 0.0], Bt03e08Contract::FIT_EXECUTION_ORDER);
        $this->assertSame(200, Bt03e08Contract::MAX_ITERATIONS);
        $this->assertSame(1e-7, Bt03e08Contract::CONVERGENCE_TOLERANCE);
        $this->assertSame(1e-10, Bt03e08Contract::OBJECTIVE_TOLERANCE);
        $this->assertSame(2000, Bt03e08Contract::BOOTSTRAP_ITERATIONS);
        $this->assertSame(20260812, Bt03e08Contract::BOOTSTRAP_SEED);
        $this->assertSame(-0.0015, Bt03e08Contract::NON_INFERIORITY_CI_LOWER_THRESHOLD);
        $this->assertSame(0.0, Bt03e08Contract::SUPERIORITY_CI_LOWER_THRESHOLD);
        $this->assertSame(-0.003, Bt03e08Contract::TEMPORAL_STABILITY_DELTA_THRESHOLD);
        $this->assertSame(0.001, Bt03e08Contract::TECHNICAL_TIE_RATE_MAX);
        $this->assertSame(-0.002, Bt03e08Contract::SUPPORTING_MIN_ALLOWED_DELTA);
        $this->assertSame(Bt03e08Contract::acceptanceGate(), $plan['acceptance_gate']);
        $this->assertSame('FORBIDDEN', $plan['2026_access']);
    }
}
