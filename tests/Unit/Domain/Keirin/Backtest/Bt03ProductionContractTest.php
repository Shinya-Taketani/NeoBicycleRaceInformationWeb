<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03ProductionContract;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use PHPUnit\Framework\TestCase;

class Bt03ProductionContractTest extends TestCase
{
    public function test_fixed_scope_order_and_execution_contract_are_canonical(): void
    {
        $scopes = (new Bt03ProductionContract)->scopes();

        $this->assertCount(72, $scopes);
        $this->assertSame(range(1, 72), array_column($scopes, 'ordinal'));
        $this->assertSame(
            ['WF_2023', Bt03SourceManifest::ENTRY_STAT_CODES[0], 'STRICT'],
            [$scopes[0]->foldCode, $scopes[0]->statCode, $scopes[0]->cohortCode],
        );
        $this->assertSame(
            ['WF_2025', Bt03SourceManifest::ENTRY_STAT_CODES[11], 'OPERATIONAL'],
            [$scopes[71]->foldCode, $scopes[71]->statCode, $scopes[71]->cohortCode],
        );
        $this->assertSame(2000, Bt03ProductionContract::BOOTSTRAP_ITERATIONS);
        $this->assertSame(20260812, Bt03ProductionContract::BOOTSTRAP_SEED);
        $this->assertSame(2004, Bt03ProductionContract::BASE_EFFECT_COUNT);
        $this->assertNotContains('WF_2026', array_column($scopes, 'foldCode'));
    }
}
