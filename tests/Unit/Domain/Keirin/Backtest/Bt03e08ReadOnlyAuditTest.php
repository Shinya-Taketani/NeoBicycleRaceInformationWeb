<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e08ReadOnlyQueryAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class Bt03e08ReadOnlyAuditTest extends TestCase
{
    public function test_outer_outcomes_require_sealed_candidates_and_finish_audits_all_years(): void
    {
        $audit = $this->started();
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $audit->recordFeatureSourceYear($year);
        }
        $audit->recordSnapshotYear(2022);
        $audit->recordSnapshotYear(2023);
        try {
            $audit->recordSnapshotYear(2024);
            $this->fail('2024 outcome before seal must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('before its candidate', $exception->getMessage());
        }
        $audit->recordCandidateManifestSealed(2024);
        $audit->recordSnapshotYear(2024);
        $audit->recordCandidateManifestSealed(2025);
        $audit->recordSnapshotYear(2025);
        $result = $audit->finish();
        $this->assertTrue($result['prediction_before_outcome_verified']);
        $this->assertSame(0, $result['2026_query_or_binding_count']);
    }

    public function test_all_2026_access_channels_are_rejected(): void
    {
        $audit = $this->started();
        foreach (['feature', 'snapshot'] as $channel) {
            try {
                $channel === 'feature' ? $audit->recordFeatureSourceYear(2026) : $audit->recordSnapshotYear(2026);
                $this->fail("2026 {$channel} access must fail.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('2026', $exception->getMessage());
            }
        }
        try {
            DB::select('SELECT ? AS forbidden_date', ['2026-01-01']);
            $this->fail('2026 SQL binding must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 query access', $exception->getMessage());
        }
    }

    private function started(): Bt03e08ReadOnlyQueryAudit
    {
        $audit = new Bt03e08ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();

        return $audit;
    }
}
