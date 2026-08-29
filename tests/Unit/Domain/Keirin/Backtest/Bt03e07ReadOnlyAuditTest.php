<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e07ReadOnlyQueryAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class Bt03e07ReadOnlyAuditTest extends TestCase
{
    public function test_2022_2023_outcomes_are_allowed_but_outer_outcomes_require_their_candidate_seal(): void
    {
        $audit = $this->started();
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $audit->recordFeatureSourceYear($year);
        }
        $audit->recordSnapshotYear(2022);
        $audit->recordSnapshotYear(2023);
        try {
            $audit->recordSnapshotYear(2024);
            $this->fail('2024 outcome access before seal must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2024 outcome access occurred before', $exception->getMessage());
        }
        $audit->recordCandidateManifestSealed(2024);
        $audit->recordSnapshotYear(2024);
        $audit->recordCandidateManifestSealed(2025);
        $audit->recordSnapshotYear(2025);
        $audit->finish();
    }

    public function test_each_outer_candidate_is_sealed_before_its_outcome_and_finish_verifies_every_partition(): void
    {
        $audit = $this->started();
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $audit->recordFeatureSourceYear($year);
        }
        $audit->recordSnapshotYear(2022);
        $audit->recordSnapshotYear(2023);
        $audit->recordCandidateManifestSealed(2024);
        $audit->recordSnapshotYear(2024);
        $audit->recordCandidateManifestSealed(2025);
        $audit->recordSnapshotYear(2025);
        $result = $audit->finish();

        $this->assertSame([2024 => true, 2025 => true], $result['candidate_manifest_sealed']);
        $this->assertSame([2022 => 1, 2023 => 1, 2024 => 1, 2025 => 1, 2026 => 0], $result['snapshot_partition_access']);
        $this->assertTrue($result['prediction_before_outcome_verified']);
    }

    public function test_2026_feature_and_sql_binding_are_rejected(): void
    {
        $audit = $this->started();
        try {
            $audit->recordFeatureSourceYear(2026);
            $this->fail('2026 feature access must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forbidden or invalid', $exception->getMessage());
        }
        try {
            DB::select('SELECT ? AS forbidden_date', ['2026-01-01']);
            $this->fail('2026 SQL binding must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 query access', $exception->getMessage());
        }
        try {
            $audit->finish();
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 audit failed', $exception->getMessage());
        }
    }

    private function started(): Bt03e07ReadOnlyQueryAudit
    {
        $audit = new Bt03e07ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();

        return $audit;
    }
}
