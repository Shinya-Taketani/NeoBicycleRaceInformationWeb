<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Console\Commands\Keirin\Bt03BinEffectsCommand;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionPlanDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionSummaryDto;
use App\Domain\Keirin\Backtest\Services\Bt03BinEffectProductionService;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class Bt03BinEffectsCommandTest extends TestCase
{
    public function test_plan_reports_fixed_contract_without_executing(): void
    {
        $service = Mockery::mock(Bt03BinEffectProductionService::class);
        $service->shouldReceive('plan')->once()->andReturn(new Bt03ProductionPlanDto(
            5, 3, 12, 2, 72, 668, 2004, 2000, 20260812,
            ['backtest_bin_effects' => true, 'backtest_bin_effect_scopes' => false],
        ));
        $service->shouldNotReceive('execute');
        $this->app->instance(Bt03BinEffectProductionService::class, $service);

        $this->artisan('keirin:backtest:bt03-bin-effects')
            ->expectsOutputToContain('BT-03 BIN EFFECT PLAN PASS')
            ->expectsOutputToContain('source_run_id=5')
            ->expectsOutputToContain('scopes=72')
            ->expectsOutputToContain('source_bins=668')
            ->expectsOutputToContain('base_effect_rows=2004')
            ->expectsOutputToContain('bootstrap_iterations=2000')
            ->expectsOutputToContain('bootstrap_seed=20260812')
            ->expectsOutputToContain('2026=BLOCKED')
            ->expectsOutputToContain('schema.backtest_bin_effects=READY')
            ->expectsOutputToContain('schema.backtest_bin_effect_scopes=PENDING_MIGRATION')
            ->assertExitCode(0);
    }

    public function test_resume_requires_execute_and_valid_positive_id(): void
    {
        $service = Mockery::mock(Bt03BinEffectProductionService::class);
        $service->shouldNotReceive('plan');
        $service->shouldNotReceive('execute');
        $this->app->instance(Bt03BinEffectProductionService::class, $service);

        $this->artisan('keirin:backtest:bt03-bin-effects', ['--resume-run-id' => '12'])
            ->expectsOutputToContain('--resume-run-id requires --execute.')
            ->assertExitCode(1);
        $this->artisan('keirin:backtest:bt03-bin-effects', ['--execute' => true, '--resume-run-id' => 'zero'])
            ->expectsOutputToContain('--resume-run-id must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_execute_passes_only_resume_identity_and_reports_summary(): void
    {
        $service = Mockery::mock(Bt03BinEffectProductionService::class);
        $service->shouldReceive('execute')->once()->with(91, Mockery::type('callable'))
            ->andReturn(new Bt03ProductionSummaryDto(91, 'run-uuid', 72, 72, 17, 2007, 1, str_repeat('a', 64)));
        $service->shouldNotReceive('plan');
        $this->app->instance(Bt03BinEffectProductionService::class, $service);

        $this->artisan('keirin:backtest:bt03-bin-effects', ['--execute' => true, '--resume-run-id' => '91'])
            ->expectsOutputToContain('BT-03 BIN EFFECT PRODUCTION SUCCEEDED')
            ->expectsOutputToContain('run_id=91')
            ->expectsOutputToContain('scopes=72/72')
            ->expectsOutputToContain('effects=2007')
            ->assertExitCode(0);
    }

    public function test_execute_schema_failure_is_reported_without_success(): void
    {
        $service = Mockery::mock(Bt03BinEffectProductionService::class);
        $service->shouldReceive('execute')->once()->andThrow(new RuntimeException('required table was missing'));
        $this->app->instance(Bt03BinEffectProductionService::class, $service);

        $this->artisan('keirin:backtest:bt03-bin-effects', ['--execute' => true])
            ->expectsOutputToContain('required table was missing')
            ->assertExitCode(1);
    }

    public function test_command_exposes_only_execute_and_resume_options(): void
    {
        $options = array_keys($this->app->make(Bt03BinEffectsCommand::class)->getDefinition()->getOptions());

        $this->assertSame(['execute', 'resume-run-id'], $options);
    }

    public function test_unknown_contract_weakening_option_is_rejected(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The "--fold" option does not exist.');

        $this->artisan('keirin:backtest:bt03-bin-effects', ['--fold' => 'WF_2023'])->run();
    }
}
