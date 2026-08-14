<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Console\Commands\Keirin\Bt02SignalEvaluationCommand;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalEvaluationSummaryDto;
use App\Domain\Keirin\Backtest\Services\Bt02SignalEvaluationService;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\InputDefinition;
use Tests\TestCase;

class Bt02SignalEvaluationCommandTest extends TestCase
{
    public function test_command_without_confirmation_fails_before_service_execution(): void
    {
        $service = Mockery::mock(Bt02SignalEvaluationService::class);
        $service->shouldNotReceive('execute');
        $this->app->instance(Bt02SignalEvaluationService::class, $service);

        $this->artisan('keirin:backtest:bt02-evaluate-signals')
            ->expectsOutputToContain('requires the explicit --execute')
            ->assertExitCode(1);
    }

    public function test_confirmed_command_reports_the_fixed_execution_summary(): void
    {
        $service = Mockery::mock(Bt02SignalEvaluationService::class);
        $service->shouldReceive('execute')->once()->andReturn(new Bt02SignalEvaluationSummaryDto(
            10,
            '00000000-0000-4000-8000-000000000010',
            3,
            12,
            432,
            216,
        ));
        $this->app->instance(Bt02SignalEvaluationService::class, $service);

        $this->artisan('keirin:backtest:bt02-evaluate-signals', ['--execute' => true])
            ->expectsOutputToContain('BT-02 SIGNAL EVALUATION SUCCEEDED')
            ->expectsOutputToContain('folds=3')
            ->expectsOutputToContain('entry_signals=12')
            ->assertExitCode(0);
    }

    public function test_confirmed_command_fails_closed_without_variable_date_options(): void
    {
        $service = Mockery::mock(Bt02SignalEvaluationService::class);
        $service->shouldReceive('execute')->once()->andThrow(new RuntimeException('preflight failed'));
        $this->app->instance(Bt02SignalEvaluationService::class, $service);

        $this->artisan('keirin:backtest:bt02-evaluate-signals', ['--execute' => true])
            ->expectsOutputToContain('preflight failed')
            ->assertExitCode(1);

        $options = array_keys($this->artisanCommandDefinition()->getOptions());
        $this->assertSame(['execute'], $options);
    }

    private function artisanCommandDefinition(): InputDefinition
    {
        return $this->app->make(Bt02SignalEvaluationCommand::class)->getDefinition();
    }
}
