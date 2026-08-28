<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e05ReadOnlyQueryAudit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Bt03e05ReadOnlyAuditTest extends TestCase
{
    public function test_only_2024_and_2025_snapshot_and_baseline_access_is_audited(): void
    {
        $audit = new Bt03e05ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordDecoderContractFrozen();
        $audit->recordSourceBundleValidated();
        foreach ([2024, 2025] as $year) {
            $audit->recordSnapshotYear($year);
            $audit->recordBaselineYear($year);
        }
        foreach ([2024, 2025] as $year) {
            $audit->recordSnapshotYear($year);
        }
        $result = $audit->finish();

        $this->assertSame(0, $result['snapshot_partition_access'][2022]);
        $this->assertSame(0, $result['snapshot_partition_access'][2023]);
        $this->assertGreaterThan(0, $result['snapshot_partition_access'][2024]);
        $this->assertGreaterThan(0, $result['snapshot_partition_access'][2025]);
        $this->assertSame(0, $result['snapshot_partition_access'][2026]);
        $this->assertSame(0, $result['baseline_feature_access'][2022]);
        $this->assertSame(0, $result['baseline_feature_access'][2023]);
        $this->assertSame(0, $result['baseline_feature_access'][2026]);
        $this->assertTrue($result['decoder_contract_frozen']);
    }

    /** @dataProvider forbiddenYearProvider */
    #[DataProvider('forbiddenYearProvider')]
    public function test_2022_2023_and_2026_semantic_access_is_rejected(int $year): void
    {
        $audit = new Bt03e05ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordDecoderContractFrozen();
        $audit->recordSourceBundleValidated();
        try {
            $audit->recordSnapshotYear($year);
            $this->fail("{$year} must be rejected.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forbidden or invalid', $exception->getMessage());
        } finally {
            foreach ([2024, 2025] as $requiredYear) {
                $audit->recordSnapshotYear($requiredYear);
                $audit->recordBaselineYear($requiredYear);
            }
            $audit->finish();
        }
    }

    /** @return iterable<string,array{int}> */
    public static function forbiddenYearProvider(): iterable
    {
        yield '2022' => [2022];
        yield '2023' => [2023];
        yield '2026' => [2026];
    }

    public function test_missing_required_development_year_access_fails_closed(): void
    {
        $audit = new Bt03e05ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordDecoderContractFrozen();
        $audit->recordSourceBundleValidated();
        $audit->recordSnapshotYear(2024);
        $audit->recordBaselineYear(2024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporal');
        $audit->finish();
    }

    public function test_2026_database_binding_is_rejected_and_counted(): void
    {
        $audit = new Bt03e05ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordDecoderContractFrozen();
        $audit->recordSourceBundleValidated();
        try {
            DB::select('SELECT ? AS forbidden_date', ['2026-01-01']);
            $this->fail('A 2026 query binding must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 query access', $exception->getMessage());
        }
        try {
            $audit->finish();
            $this->fail('A detected 2026 query must fail the final audit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2026 audit failed', $exception->getMessage());
        }
    }
}
