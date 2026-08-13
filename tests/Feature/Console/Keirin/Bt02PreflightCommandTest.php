<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Console\Commands\Keirin\Bt02PreflightCommand;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt02PreflightCommandTest extends TestCase
{
    public function test_command_has_no_contract_weakening_options_and_reports_pass(): void
    {
        $service = Mockery::mock(Bt02FingerprintPreflightService::class);
        $service->shouldReceive('run')->once()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $this->app->instance(Bt02FingerprintPreflightService::class, $service);

        $this->artisan('keirin:backtest:bt02-preflight')
            ->expectsOutputToContain('BT-02 PREFLIGHT PASS')
            ->expectsOutputToContain('verified runs=56')
            ->expectsOutputToContain('source fingerprint match=56/56')
            ->expectsOutputToContain('content fingerprint match=56/56')
            ->assertExitCode(0);

        $definition = $this->app->make(Bt02PreflightCommand::class)->getDefinition();
        $this->assertSame([], array_keys($definition->getOptions()));
    }

    public function test_command_fails_closed_without_printing_connection_secrets(): void
    {
        config()->set('database.connections.pgsql.password', 'never-print-this-secret');
        $service = Mockery::mock(Bt02FingerprintPreflightService::class);
        $service->shouldReceive('run')->once()->andThrow(new RuntimeException('fingerprint mismatch'));
        $this->app->instance(Bt02FingerprintPreflightService::class, $service);

        $this->artisan('keirin:backtest:bt02-preflight')
            ->expectsOutputToContain('fingerprint mismatch')
            ->doesntExpectOutputToContain('never-print-this-secret')
            ->assertExitCode(1);
    }
}
