<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e06ReadOnlyQueryAudit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Bt03e06ReadOnlyAuditTest extends TestCase
{
    public function test_candidate_manifests_are_sealed_before_outcome_access(): void
    {
        $audit = new Bt03e06ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();
        foreach ([2024, 2025] as $year) {
            $audit->recordFeatureSourceYear($year);
            $audit->recordCandidateManifestSealed($year);
        }
        foreach ([2024, 2025] as $year) {
            $audit->recordSnapshotYear($year);
        }
        $result = $audit->finish();

        $this->assertTrue($result['prediction_before_outcome_verified']);
        $this->assertSame([2024 => true, 2025 => true], $result['candidate_manifest_sealed']);
        $this->assertSame(0, $result['feature_partition_access'][2022]);
        $this->assertSame(0, $result['feature_partition_access'][2023]);
        $this->assertSame(0, $result['feature_partition_access'][2026]);
        $this->assertSame(0, $result['snapshot_partition_access'][2026]);
    }

    public function test_outcome_access_before_both_candidate_manifests_are_sealed_is_rejected(): void
    {
        $audit = $this->startedAudit();
        $audit->recordFeatureSourceYear(2024);
        $audit->recordCandidateManifestSealed(2024);
        try {
            $audit->recordSnapshotYear(2024);
            $this->fail('Outcome access before both candidate manifests must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('before all candidate manifests', $exception->getMessage());
        } finally {
            $audit->recordFeatureSourceYear(2025);
            $audit->recordCandidateManifestSealed(2025);
            $audit->recordSnapshotYear(2024);
            $audit->recordSnapshotYear(2025);
            $audit->finish();
        }
    }

    #[DataProvider('forbiddenFeatureYears')]
    public function test_2022_2023_and_2026_feature_access_is_rejected(int $year): void
    {
        $audit = $this->startedAudit();
        try {
            $audit->recordFeatureSourceYear($year);
            $this->fail("{$year} feature access must fail.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forbidden or invalid', $exception->getMessage());
        } finally {
            foreach ([2024, 2025] as $required) {
                $audit->recordFeatureSourceYear($required);
                $audit->recordCandidateManifestSealed($required);
            }
            $audit->recordSnapshotYear(2024);
            $audit->recordSnapshotYear(2025);
            $audit->finish();
        }
    }

    public function test_2026_sql_binding_is_rejected(): void
    {
        $audit = $this->startedAudit();
        try {
            DB::select('SELECT ? AS forbidden_date', ['2026-01-01']);
            $this->fail('A 2026 SQL binding must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 query access', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026 audit failed');
        $audit->finish();
    }

    /** @return iterable<string,array{int}> */
    public static function forbiddenFeatureYears(): iterable
    {
        yield '2022' => [2022];
        yield '2023' => [2023];
        yield '2026' => [2026];
    }

    private function startedAudit(): Bt03e06ReadOnlyQueryAudit
    {
        $audit = new Bt03e06ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordContractFrozen();
        $audit->recordSourceBundleValidated();

        return $audit;
    }
}
