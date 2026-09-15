<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation;
use PHPUnit\Framework\TestCase;

class TacticalHistoryGateTest extends TestCase
{
    public function test_fixed_strict_and_inclusive_incremental_gate_boundaries(): void
    {
        $gate = new Evaluation(new Bt03e05MetricEvaluator, new Bt03e05PairedBootstrap(new Type7Quantile), new Bt03e05AcceptanceGate);
        $outer = array_fill_keys([2024, 2025], ['delta' => array_fill_keys(Evaluation::PRIMARY, 0.001)]);
        $ci = array_fill_keys(Evaluation::PRIMARY, ['ci_lower' => 0.0001, 'ci_upper' => 0.002]);
        $this->assertSame('PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY', $gate->incrementalGate($outer, $ci, true)['status']);
        $this->assertSame('NOT_PASSED', $gate->incrementalGate($outer, $ci, false)['status']);
        $ni = $ci;
        $ni['WINNER_HIT_AT_1']['ci_lower'] = -0.0015;
        $this->assertFalse($gate->incrementalGate($outer, $ni, true)['non_inferiority']);
        $ni['WINNER_HIT_AT_1']['ci_lower'] = -0.001499999;
        $this->assertTrue($gate->incrementalGate($outer, $ni, true)['non_inferiority']);
        $zero = $ci;
        $zero['POSITION_HIT_RATE_AT_3']['ci_lower'] = 0.0;
        $this->assertFalse($gate->incrementalGate($outer, $zero, true)['superiority']);
        $outer[2024]['delta']['POSITION_2_ACCURACY'] = -0.003;
        $outer[2025]['delta']['POSITION_HIT_RATE_AT_3'] = 0.0;
        $this->assertTrue($gate->incrementalGate($outer, $ci, true)['temporal']);
        $outer[2024]['delta']['POSITION_2_ACCURACY'] = -0.003000001;
        $this->assertFalse($gate->incrementalGate($outer, $ci, true)['temporal']);
    }
}
