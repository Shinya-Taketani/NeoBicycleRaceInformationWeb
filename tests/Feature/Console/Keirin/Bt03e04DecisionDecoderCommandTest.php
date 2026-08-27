<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03e04DevelopmentEvaluationService;
use Mockery\MockInterface;
use Tests\TestCase;

class Bt03e04DecisionDecoderCommandTest extends TestCase
{
    public function test_plan_needs_neither_source_bundle_nor_database_execution(): void
    {
        $this->mock(Bt03e04DevelopmentEvaluationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $this->artisan('keirin:backtest:bt03e04 --plan')
            ->expectsOutputToContain('BT-03E-04 PLAN')
            ->expectsOutputToContain('decoder_WINNER_HIT_AT_1=PRIMARY_COHERENT_POSITION')
            ->expectsOutputToContain('decoder_EXACT_ORDERED_TOP3_RATE=MAP_ORDERED_TOP3')
            ->expectsOutputToContain('model_fitting=FORBIDDEN')
            ->expectsOutputToContain('2026_access=FORBIDDEN')
            ->expectsOutputToContain('read_only=true')
            ->assertExitCode(0);
    }

    public function test_command_requires_exactly_one_mode_and_source_for_execute(): void
    {
        $this->artisan('keirin:backtest:bt03e04')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e04 --plan --execute')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e04 --execute')->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03e04 --plan --source-bundle=/tmp/source')->assertExitCode(1);
    }

    public function test_execute_passes_the_required_fixed_source_bundle(): void
    {
        $metrics = ['baseline' => [], 'candidate' => [], 'delta' => []];
        foreach (['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'] as $metric) {
            $metrics['baseline'][$metric] = 0.2;
            $metrics['candidate'][$metric] = 0.3;
            $metrics['delta'][$metric] = 0.1;
        }
        $this->mock(Bt03e04DevelopmentEvaluationService::class, function (MockInterface $mock) use ($metrics): void {
            $mock->shouldReceive('run')->once()->with('/tmp/source', '/tmp', null)->andReturn([
                'outer_2024' => ['metrics' => $metrics],
                'outer_2025' => ['metrics' => $metrics],
                'acceptance_gate' => ['status' => 'PASS / GO_TO_FREEZE', 'gates' => ['integrity' => true]],
                'reproducibility_verification' => ['status' => 'VERIFIED'],
                'audit' => ['2026_access_count' => 0],
                'artifacts' => ['bundle_directory' => '/tmp/e04'],
            ]);
        });

        $this->artisan('keirin:backtest:bt03e04 --execute --source-bundle=/tmp/source')
            ->expectsOutputToContain('BT-03E-04 DEVELOPMENT EVALUATION COMPLETE')
            ->expectsOutputToContain('2024 WIN baseline=0.20000000 candidate=0.30000000 delta=+0.10000000')
            ->expectsOutputToContain('2026_access=0')
            ->assertExitCode(0);
    }
}
