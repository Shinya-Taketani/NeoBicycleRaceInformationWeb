<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e03DevelopmentEvaluationService;
use Mockery\MockInterface;
use Tests\TestCase;

class Bt03e03PositionProbabilityCommandTest extends TestCase
{
    public function test_plan_displays_position_probability_contract_without_fitting(): void
    {
        $this->mock(Bt03e03DevelopmentEvaluationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $this->artisan('keirin:backtest:bt03e03 --plan')
            ->expectsOutputToContain('BT-03E-03 PLAN')
            ->expectsOutputToContain('positions=POSITION_1,POSITION_2,POSITION_3')
            ->expectsOutputToContain('probability=EXACT_POSITION_MARGINALIZATION')
            ->expectsOutputToContain('alpha_combination=FORBIDDEN')
            ->expectsOutputToContain('2026_access=FORBIDDEN')
            ->assertExitCode(0);
    }

    public function test_execute_summary_includes_all_position_and_gate_outputs(): void
    {
        $metricCodes = [
            'WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY',
            'POSITION_HIT_RATE_AT_3', 'EXACT_ORDERED_TOP3_RATE', 'EXACT_TOP3_SET_RATE',
        ];
        $baseline = $candidate = $delta = [];
        foreach ($metricCodes as $metric) {
            $baseline[$metric] = 0.2;
            $candidate[$metric] = 0.3;
            $delta[$metric] = 0.1;
        }
        $metrics = ['baseline' => $baseline, 'candidate' => $candidate, 'delta' => $delta];
        $this->mock(Bt03e03DevelopmentEvaluationService::class, function (MockInterface $mock) use ($metrics): void {
            $mock->shouldReceive('run')->once()->andReturn([
                'outer_2024' => ['metrics' => $metrics],
                'outer_2025' => ['metrics' => $metrics],
                'acceptance_gate' => [
                    'status' => 'PASS / GO_TO_FREEZE',
                    'gates' => [
                        'integrity' => true,
                        'non_inferiority' => true,
                        'superiority' => true,
                        'temporal_stability' => true,
                        'supporting' => true,
                        'tie_quality' => true,
                        'position_redesign' => true,
                        'win_preservation' => true,
                    ],
                ],
                'reproducibility_verification' => ['status' => 'VERIFIED'],
                'audit' => ['2026_access_count' => 0],
                'artifacts' => ['bundle_directory' => '/tmp/bt03e03'],
            ]);
        });

        $this->artisan('keirin:backtest:bt03e03 --execute')
            ->expectsOutputToContain('2024 WIN baseline=0.20000000 candidate=0.30000000 delta=+0.10000000')
            ->expectsOutputToContain('2025 P3 baseline=0.20000000 candidate=0.30000000 delta=+0.10000000')
            ->expectsOutputToContain('gate_position_redesign=PASS')
            ->expectsOutputToContain('gate_win_preservation=PASS')
            ->expectsOutputToContain('2026_access=0')
            ->assertExitCode(0);
    }

    public function test_command_requires_exactly_one_mode(): void
    {
        $this->artisan('keirin:backtest:bt03e03')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e03 --plan --execute')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e03 --plan --verify-reproducibility=/tmp/result.json')->assertExitCode(1);
    }
}
