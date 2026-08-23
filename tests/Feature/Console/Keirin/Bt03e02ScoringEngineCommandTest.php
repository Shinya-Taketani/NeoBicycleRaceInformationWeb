<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e02DevelopmentEvaluationService;
use Mockery\MockInterface;
use Tests\TestCase;

class Bt03e02ScoringEngineCommandTest extends TestCase
{
    public function test_plan_displays_the_frozen_contract_without_executing_a_fit(): void
    {
        $this->mock(Bt03e02DevelopmentEvaluationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $this->artisan('keirin:backtest:bt03e02 --plan')
            ->expectsOutputToContain('BT-03E-02 PLAN')
            ->expectsOutputToContain('alpha_candidates=231')
            ->expectsOutputToContain('2026_access=FORBIDDEN')
            ->expectsOutputToContain('read_only=true')
            ->assertExitCode(0);
    }

    public function test_first_execute_reports_metrics_but_requires_reproducibility_verification(): void
    {
        $metric = [
            'baseline' => ['WINNER_HIT_AT_1' => 0.38, 'EXACT_ORDERED_TOP3_RATE' => 0.04, 'EXACT_TOP3_SET_RATE' => 0.15],
            'candidate' => ['WINNER_HIT_AT_1' => 0.39, 'EXACT_ORDERED_TOP3_RATE' => 0.05, 'EXACT_TOP3_SET_RATE' => 0.16],
            'delta' => ['WINNER_HIT_AT_1' => 0.01, 'EXACT_ORDERED_TOP3_RATE' => 0.01, 'EXACT_TOP3_SET_RATE' => 0.01],
        ];
        $this->mock(Bt03e02DevelopmentEvaluationService::class, function (MockInterface $mock) use ($metric): void {
            $mock->shouldReceive('run')->once()->with('/tmp', null)->andReturn([
                'outer_2024' => ['metrics' => $metric],
                'outer_2025' => ['metrics' => $metric],
                'acceptance_gate' => [
                    'status' => 'REPRODUCIBILITY VERIFICATION REQUIRED',
                    'performance_status' => 'PASS / GO_TO_FREEZE',
                    'gates' => [
                        'integrity' => false,
                        'non_inferiority' => true,
                        'superiority' => true,
                        'temporal_stability' => true,
                        'supporting' => true,
                        'tie_quality' => true,
                    ],
                ],
                'reproducibility_verification' => ['status' => 'REPRODUCIBILITY VERIFICATION REQUIRED'],
                'audit' => ['2026_access_count' => 0],
                'artifacts' => ['bundle_directory' => '/tmp/bt03e02'],
            ]);
        });

        $this->artisan('keirin:backtest:bt03e02 --execute')
            ->expectsOutputToContain('単勝 baseline=0.38000000 candidate=0.39000000')
            ->expectsOutputToContain('3連単 baseline=0.04000000 candidate=0.05000000')
            ->expectsOutputToContain('3連複 baseline=0.15000000 candidate=0.16000000')
            ->expectsOutputToContain('gate_integrity=FAIL')
            ->expectsOutputToContain('gate_tie_quality=PASS')
            ->expectsOutputToContain('status=REPRODUCIBILITY VERIFICATION REQUIRED')
            ->expectsOutputToContain('reproducibility=REPRODUCIBILITY VERIFICATION REQUIRED')
            ->expectsOutputToContain('2026_access=0')
            ->assertExitCode(0);
    }

    public function test_verified_rerun_can_report_go_to_freeze(): void
    {
        $artifact = '/tmp/previous-result.json';
        $metric = [
            'baseline' => ['WINNER_HIT_AT_1' => 0.38, 'EXACT_ORDERED_TOP3_RATE' => 0.04, 'EXACT_TOP3_SET_RATE' => 0.15],
            'candidate' => ['WINNER_HIT_AT_1' => 0.39, 'EXACT_ORDERED_TOP3_RATE' => 0.05, 'EXACT_TOP3_SET_RATE' => 0.16],
            'delta' => ['WINNER_HIT_AT_1' => 0.01, 'EXACT_ORDERED_TOP3_RATE' => 0.01, 'EXACT_TOP3_SET_RATE' => 0.01],
        ];
        $this->mock(Bt03e02DevelopmentEvaluationService::class, function (MockInterface $mock) use ($artifact, $metric): void {
            $mock->shouldReceive('run')->once()->with('/tmp', $artifact)->andReturn([
                'outer_2024' => ['metrics' => $metric],
                'outer_2025' => ['metrics' => $metric],
                'acceptance_gate' => [
                    'status' => 'PASS / GO_TO_FREEZE',
                    'gates' => [
                        'integrity' => true,
                        'non_inferiority' => true,
                        'superiority' => true,
                        'temporal_stability' => true,
                        'supporting' => true,
                        'tie_quality' => true,
                    ],
                ],
                'reproducibility_verification' => ['status' => 'VERIFIED'],
                'audit' => ['2026_access_count' => 0],
                'artifacts' => ['bundle_directory' => '/tmp/bt03e02'],
            ]);
        });

        $this->artisan("keirin:backtest:bt03e02 --execute --verify-reproducibility={$artifact}")
            ->expectsOutputToContain('gate_integrity=PASS')
            ->expectsOutputToContain('status=PASS / GO_TO_FREEZE')
            ->expectsOutputToContain('reproducibility=VERIFIED')
            ->assertExitCode(0);
    }

    public function test_command_requires_exactly_one_mode(): void
    {
        $this->artisan('keirin:backtest:bt03e02')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e02 --plan --execute')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e02 --plan --verify-reproducibility=/tmp/result.json')->assertExitCode(1);
    }
}
