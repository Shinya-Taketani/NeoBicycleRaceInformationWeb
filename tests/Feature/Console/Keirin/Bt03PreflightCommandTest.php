<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Console\Commands\Keirin\Bt03PreflightCommand;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\Services\Bt03PreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt03PreflightCommandTest extends TestCase
{
    public function test_command_has_no_contract_weakening_options_and_reports_all_fixed_checks(): void
    {
        $service = Mockery::mock(Bt03PreflightService::class);
        $service->shouldReceive('run')->once()->andReturn($this->summary());
        $this->app->instance(Bt03PreflightService::class, $service);

        $this->artisan('keirin:backtest:bt03-preflight')
            ->expectsOutputToContain('BT-03 PREFLIGHT PASS')
            ->expectsOutputToContain('source BT-02 run_id=5')
            ->expectsOutputToContain('folds=3/3')
            ->expectsOutputToContain('signal specs=14/14')
            ->expectsOutputToContain('models=432/432')
            ->expectsOutputToContain('metrics=648/648')
            ->expectsOutputToContain('effect bins=668/668')
            ->expectsOutputToContain('objective version match=432/432')
            ->expectsOutputToContain('optimizer version match=432/432')
            ->expectsOutputToContain('outcome snapshot manifest=PASS')
            ->expectsOutputToContain('BT-02 baseline fingerprint=PASS (4/4)')
            ->expectsOutputToContain('BT-02 source fingerprint=56/56')
            ->expectsOutputToContain('BT-02 content fingerprint=56/56')
            ->assertExitCode(0);

        $definition = $this->app->make(Bt03PreflightCommand::class)->getDefinition();
        $this->assertSame([], array_keys($definition->getOptions()));
    }

    public function test_command_fails_closed_without_printing_connection_secrets(): void
    {
        config()->set('database.connections.pgsql.password', 'never-print-this-secret');
        $service = Mockery::mock(Bt03PreflightService::class);
        $service->shouldReceive('run')->once()->andThrow(new RuntimeException('BT-03 source fingerprint mismatch'));
        $this->app->instance(Bt03PreflightService::class, $service);

        $this->artisan('keirin:backtest:bt03-preflight')
            ->expectsOutputToContain('BT-03 source fingerprint mismatch')
            ->doesntExpectOutputToContain('never-print-this-secret')
            ->assertExitCode(1);
    }

    private function summary(): Bt03PreflightSummaryDto
    {
        $hash = str_repeat('a', 64);

        return new Bt03PreflightSummaryDto(
            new Bt03SourceVerificationDto(
                5,
                3,
                14,
                432,
                648,
                668,
                432,
                432,
                new Bt03SourceArtifactFingerprintsDto($hash, $hash, $hash, $hash, $hash, $hash),
                'private/backtest/bt02/outcome-context/'.$hash,
            ),
            $hash,
            4,
            new Bt02PreflightSummaryDto(56, 56, 56, $hash),
            Bt03SourceManifest::HASH,
        );
    }
}
