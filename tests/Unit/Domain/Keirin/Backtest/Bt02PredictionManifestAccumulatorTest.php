<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02PredictionManifestDto;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use PHPUnit\Framework\TestCase;

class Bt02PredictionManifestAccumulatorTest extends TestCase
{
    /** @var list<array{int, int, int, float, float}> */
    private const ROWS = [
        [10, 101, 1, 0.25, 0.5],
        [10, 102, 0, 0.75, 0.625],
        [11, 103, 1, 0.125, 0.875],
    ];

    public function test_it_exactly_matches_all_bt02_prediction_spool_manifests(): void
    {
        $spool = new Bt02PredictionSpool($this->identity());
        $accumulator = new Bt02PredictionManifestAccumulator($this->identity());
        try {
            foreach (self::ROWS as $row) {
                $spool->append(...$row);
                $accumulator->append(...$row);
            }
            $spoolMetadata = $spool->seal();
            $manifests = $accumulator->seal();

            $this->assertSame($spoolMetadata->rowCount, $manifests->rowCount);
            $this->assertSame($spoolMetadata->raceCount, $manifests->raceCount);
            $this->assertSame($spoolMetadata->baselinePredictionManifestSha256, $manifests->baselinePredictionManifestSha256);
            $this->assertSame($spoolMetadata->incrementalPredictionManifestSha256, $manifests->incrementalPredictionManifestSha256);
            $this->assertSame($spoolMetadata->outcomeManifestSha256, $manifests->outcomeManifestSha256);
        } finally {
            $spool->cleanup();
        }
    }

    public function test_manifest_contract_is_sensitive_to_order_identity_float_label_and_model_prefixes(): void
    {
        $original = $this->manifests($this->identity(), self::ROWS);

        $rowOrderChanged = [self::ROWS[2], self::ROWS[0], self::ROWS[1]];
        $entryChanged = self::ROWS;
        $entryChanged[1][1] = 104;
        $baselineChanged = self::ROWS;
        $baselineChanged[0][3] = $this->nextFloat(self::ROWS[0][3]);
        $labelChanged = self::ROWS;
        $labelChanged[0][2] = 0;
        $baselineModelChanged = $this->identity();
        $baselineModelChanged['baseline_model_hash'] = str_repeat('c', 64);
        $incrementalModelChanged = $this->identity();
        $incrementalModelChanged['incremental_model_hash'] = str_repeat('d', 64);

        $this->assertNotSame($original->baselinePredictionManifestSha256, $this->manifests($this->identity(), $rowOrderChanged)->baselinePredictionManifestSha256);
        $this->assertNotSame($original->baselinePredictionManifestSha256, $this->manifests($this->identity(), $entryChanged)->baselinePredictionManifestSha256);
        $this->assertNotSame($original->baselinePredictionManifestSha256, $this->manifests($this->identity(), $baselineChanged)->baselinePredictionManifestSha256);
        $this->assertNotSame($original->outcomeManifestSha256, $this->manifests($this->identity(), $labelChanged)->outcomeManifestSha256);
        $this->assertNotSame($original->baselinePredictionManifestSha256, $this->manifests($baselineModelChanged, self::ROWS)->baselinePredictionManifestSha256);
        $this->assertNotSame($original->incrementalPredictionManifestSha256, $this->manifests($incrementalModelChanged, self::ROWS)->incrementalPredictionManifestSha256);
    }

    /** @return array<string, string> */
    private function identity(): array
    {
        return [
            'source_manifest_hash' => str_repeat('1', 64),
            'baseline_fingerprint_manifest_hash' => str_repeat('2', 64),
            'fold' => 'WF_2023',
            'stat_code' => 'STAT-07',
            'cohort' => 'STRICT',
            'label_code' => 'IS_WIN',
            'baseline_model_hash' => str_repeat('a', 64),
            'incremental_model_hash' => str_repeat('b', 64),
        ];
    }

    /** @param array<string, string> $identity @param list<array{int, int, int, float, float}> $rows */
    private function manifests(array $identity, array $rows): Bt02PredictionManifestDto
    {
        $accumulator = new Bt02PredictionManifestAccumulator($identity);
        foreach ($rows as $row) {
            $accumulator->append(...$row);
        }

        return $accumulator->seal();
    }

    private function nextFloat(float $value): float
    {
        $bits = unpack('J', pack('E', $value))[1];

        return unpack('E', pack('J', $bits + 1))[1];
    }
}
