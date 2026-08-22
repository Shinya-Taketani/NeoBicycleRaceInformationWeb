<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03eHistoricalForwardScoringService;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class Bt03eHistoricalForwardScoringCommandTest extends TestCase
{
    public function test_command_reports_the_frozen_historical_forward_result(): void
    {
        $this->mock(Bt03eHistoricalForwardScoringService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->with('/tmp')->andReturn([
                'chosen_candidate' => ['base_step' => 10, 'weights' => ['STAT-07' => 20]],
                'training_2023' => ['race_count' => 100, 'metrics' => ['POSITION_HIT_RATE_AT_3' => 0.25]],
                'evaluation_2024' => ['race_count' => 110, 'point_engine_metrics' => ['POSITION_HIT_RATE_AT_3' => 0.24]],
                'artifacts' => ['json' => '/tmp/result.json', 'csv' => '/tmp/result.csv'],
            ]);
        });

        $this->artisan('keirin:backtest:bt03e-historical-forward-score')
            ->expectsOutputToContain('source=run:6/WF_2023/OPERATIONAL')
            ->expectsOutputToContain('training_races=100')
            ->expectsOutputToContain('evaluation_races=110')
            ->expectsOutputToContain('DB writes=0; 2025 access=0; 2026 access=0')
            ->assertExitCode(0);
    }

    public function test_command_fails_closed_when_source_integrity_fails(): void
    {
        $this->mock(Bt03eHistoricalForwardScoringService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('source drift'));
        });

        $this->artisan('keirin:backtest:bt03e-historical-forward-score')
            ->expectsOutputToContain('source drift')
            ->assertExitCode(1);
    }
}
