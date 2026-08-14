<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt02PredictionSpoolTest extends TestCase
{
    public function test_prediction_and_outcome_manifests_are_deterministic_and_separated(): void
    {
        $identity = ['fold' => 'WF_2023', 'stat' => 'STAT-07', 'label_code' => 'IS_WIN'];
        $first = $this->spool($identity, [[1, 10, 1, 0.25, 0.5], [1, 11, 0, 0.75, 0.5], [2, 20, 0, 0.1, 0.2]]);
        $same = $this->spool($identity, [[1, 10, 1, 0.25, 0.5], [1, 11, 0, 0.75, 0.5], [2, 20, 0, 0.1, 0.2]]);
        $labelChanged = $this->spool($identity, [[1, 10, 0, 0.25, 0.5], [1, 11, 1, 0.75, 0.5], [2, 20, 0, 0.1, 0.2]]);
        $predictionChanged = $this->spool($identity, [[1, 10, 1, 0.2, 0.5], [1, 11, 0, 0.75, 0.5], [2, 20, 0, 0.1, 0.2]]);

        try {
            $this->assertEquals($first->metadata(), $same->metadata());
            $this->assertSame($first->metadata()->baselinePredictionManifestSha256, $labelChanged->metadata()->baselinePredictionManifestSha256);
            $this->assertSame($first->metadata()->incrementalPredictionManifestSha256, $labelChanged->metadata()->incrementalPredictionManifestSha256);
            $this->assertNotSame($first->metadata()->outcomeManifestSha256, $labelChanged->metadata()->outcomeManifestSha256);
            $this->assertNotSame($first->metadata()->baselinePredictionManifestSha256, $predictionChanged->metadata()->baselinePredictionManifestSha256);
            $this->assertSame($first->metadata()->incrementalPredictionManifestSha256, $predictionChanged->metadata()->incrementalPredictionManifestSha256);
            $this->assertCount(2, $first->racePayloads());
            $this->assertSame([1, 0], $first->racePayloads()[0]['labels']);
            $this->assertSame(3, $first->metadata()->rowCount);
            $this->assertSame(2, $first->metadata()->raceCount);
            $this->assertSame(filesize($first->path()), $first->metadata()->byteCount);
        } finally {
            $first->cleanup();
            $same->cleanup();
            $labelChanged->cleanup();
            $predictionChanged->cleanup();
        }
    }

    #[DataProvider('corruptions')]
    public function test_replay_fails_closed_for_any_sealed_file_corruption(callable $corrupt): void
    {
        $spool = $this->spool(['fold' => 'WF_2023'], [[1, 10, 1, 0.25, 0.5], [1, 11, 0, 0.75, 0.5]]);
        try {
            $corrupt($spool->path());
            $this->expectException(RuntimeException::class);
            iterator_to_array($spool->rows());
        } finally {
            $spool->cleanup();
        }
    }

    /** @return iterable<string, array{callable(string): void}> */
    public static function corruptions(): iterable
    {
        yield 'one byte changed with the same byte and row counts' => [static function (string $path): void {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            $offset = strpos($contents, '0.25');
            self::assertNotFalse($offset);
            $contents[$offset + 2] = '6';
            file_put_contents($path, $contents);
        }];
        yield 'truncated' => [static function (string $path): void {
            $handle = fopen($path, 'c+b');
            self::assertIsResource($handle);
            ftruncate($handle, max(0, filesize($path) - 5));
            fclose($handle);
        }];
        yield 'appended' => [static function (string $path): void {
            file_put_contents($path, "tamper\n", FILE_APPEND);
        }];
    }

    public function test_duplicate_and_non_canonical_prediction_identity_are_rejected(): void
    {
        $duplicate = new Bt02PredictionSpool(['fold' => 'WF_2023']);
        $duplicate->append(1, 10, 1, 0.2, 0.3);
        try {
            $this->expectException(RuntimeException::class);
            $duplicate->append(1, 10, 0, 0.3, 0.4);
        } finally {
            $duplicate->cleanup();
        }
    }

    public function test_race_and_entry_order_violation_is_rejected(): void
    {
        $spool = new Bt02PredictionSpool(['fold' => 'WF_2023']);
        $spool->append(2, 20, 1, 0.2, 0.3);
        try {
            $this->expectException(RuntimeException::class);
            $spool->append(1, 10, 0, 0.3, 0.4);
        } finally {
            $spool->cleanup();
        }
    }

    /** @param array<string, int|string> $identity @param list<array{int, int, int, float, float}> $rows */
    private function spool(array $identity, array $rows): Bt02PredictionSpool
    {
        $spool = new Bt02PredictionSpool($identity);
        foreach ($rows as $row) {
            $spool->append(...$row);
        }
        $spool->seal();

        return $spool;
    }
}
