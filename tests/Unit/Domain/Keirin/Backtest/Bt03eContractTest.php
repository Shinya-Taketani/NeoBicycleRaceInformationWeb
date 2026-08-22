<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyQueryAudit;
use Tests\TestCase;

class Bt03eContractTest extends TestCase
{
    public function test_rule_generation_contract_is_fixed_to_wf_2023_operational_only(): void
    {
        $this->assertSame(6, Bt03eContract::SOURCE_RUN_ID);
        $this->assertSame('WF_2023', Bt03eContract::SOURCE_FOLD);
        $this->assertSame('OPERATIONAL', Bt03eContract::COHORT);
        $this->assertNotContains('WF_2024', [Bt03eContract::SOURCE_FOLD]);
        $this->assertNotContains('WF_2025', [Bt03eContract::SOURCE_FOLD]);
        $this->assertSame(2023, Bt03eContract::TRAINING_YEAR);
        $this->assertSame(2024, Bt03eContract::EVALUATION_YEAR);
    }

    public function test_read_only_audit_reports_zero_2025_and_2026_access(): void
    {
        $audit = new Bt03eReadOnlyQueryAudit;
        $audit->start();
        $audit->recordSnapshotYear(2023);
        $audit->recordSnapshotYear(2024);
        $summary = $audit->finish();

        $this->assertSame(0, $summary['db_write_count']);
        $this->assertSame([2025 => 0, 2026 => 0], $summary['blocked_year_query_or_binding_access']);
        $this->assertSame([2023 => 1, 2024 => 1, 2025 => 0, 2026 => 0], $summary['snapshot_partition_access']);
    }
}
