<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use RuntimeException;

final class Bt03e07PredictionDatasetBuilder
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly EffectBinBuilder $binBuilder,
        private readonly Bt03e07ReadOnlyQueryAudit $audit,
    ) {}

    public function buildRaw(int $year, Bt03e05RaceSpool $source, string $temporaryDirectory): Bt03e02RaceSpool
    {
        if (! in_array($year, Bt03e07Contract::OUTER_YEARS, true)) {
            throw new RuntimeException('BT-03E-07 prediction year was outside 2024/2025.');
        }
        $spool = new Bt03e02RaceSpool('RAW', $this->path($temporaryDirectory, "prediction-raw-{$year}"));
        try {
            $chunk = [];
            foreach ($source->races() as $race) {
                if (($race['year'] ?? null) !== $year) {
                    throw new RuntimeException('BT-03E-07 source prediction year drifted.');
                }
                $chunk[] = $race;
                if (count($chunk) === self::CHUNK_SIZE) {
                    $this->appendChunk($spool, $year, $chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->appendChunk($spool, $year, $chunk);
            }
            $spool->seal();

            return $spool;
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /** @param list<Bt03e02RaceSpool> $rawSpools */
    public function buildBinned(array $rawSpools, Bt03e02ParameterLayout $layout, string $temporaryDirectory, string $role): Bt03e02RaceSpool
    {
        $spool = new Bt03e02RaceSpool('BINNED', $this->path($temporaryDirectory, 'prediction-binned-'.$role));
        try {
            foreach ($rawSpools as $raw) {
                foreach ($raw->races() as $race) {
                    $entries = [];
                    foreach ($race['entries'] as $entry) {
                        $entry['bins'] = $layout->assign($entry['signals'], $this->binBuilder);
                        unset($entry['signals']);
                        $entries[] = $entry;
                    }
                    $spool->append(['year' => $race['year'], 'race_id' => $race['race_id'], 'entries' => $entries]);
                }
            }
            $spool->seal();

            return $spool;
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /** @param list<array<string,mixed>> $races */
    private function appendChunk(Bt03e02RaceSpool $spool, int $year, array $races): void
    {
        $raceIds = array_map(static fn (array $race): int => (int) $race['race_id'], $races);
        $this->audit->recordFeatureSourceYear($year);
        $baselineByRace = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $raceIds);
        $signals = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
            $this->audit->recordFeatureSourceYear($year);
            $signals[$statCode] = $this->signalFeatures->forRaces(
                $this->signalManifest->for($year, $statCode)->featureRunId,
                $statCode,
                $raceIds,
            );
        }
        foreach ($races as $sourceRace) {
            $raceId = (int) $sourceRace['race_id'];
            $baseline = $baselineByRace[$raceId] ?? [];
            $sourceBikes = array_map('intval', array_column($sourceRace['entries'], 'bike'));
            $baselineBikes = array_map(static fn (object $feature): int => $feature->bikeNumber, $baseline);
            $sortedSource = $sourceBikes;
            $sortedBaseline = $baselineBikes;
            sort($sortedSource, SORT_NUMERIC);
            sort($sortedBaseline, SORT_NUMERIC);
            if ($baseline === [] || $sortedSource !== $sortedBaseline) {
                throw new RuntimeException("BT-03E-07 source E03 and current STAT-01 entrants differed for race {$raceId}.");
            }
            $signalMaps = [];
            foreach (Bt03e07Contract::STAT_CODES as $statCode) {
                foreach ($signals[$statCode][$raceId] ?? [] as $signal) {
                    if (isset($signalMaps[$statCode][$signal->raceEntryId])) {
                        throw new RuntimeException("BT-03E-07 {$statCode} duplicated a race entry.");
                    }
                    $signalMaps[$statCode][$signal->raceEntryId] = $signal;
                }
            }
            $raws = array_map(static fn (object $feature): float => (float) $feature->raceScoreRaw, $baseline);
            $mean = array_sum($raws) / count($raws);
            $deviation = sqrt(array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $raws)) / count($raws));
            $entriesByBike = [];
            foreach ($baseline as $feature) {
                $signalValues = [];
                foreach (Bt03e07Contract::STAT_CODES as $statCode) {
                    $signal = $signalMaps[$statCode][$feature->raceEntryId] ?? null;
                    $signalValues[] = $signal !== null && $this->signalEligibility->eligible($statCode, Bt02SignalCohort::Operational, $signal)
                        ? $signal->primaryValue
                        : null;
                }
                $entriesByBike[$feature->bikeNumber] = [
                    'id' => $feature->raceEntryId,
                    'bike' => $feature->bikeNumber,
                    'raw' => (float) $feature->raceScoreRaw,
                    'stat01_rank' => $feature->raceScoreRank,
                    'anchor' => $deviation > 0.0 ? ((float) $feature->raceScoreRaw - $mean) / $deviation : 0.0,
                    'anchor_status' => $deviation > 0.0 ? 'AVAILABLE' : 'ZERO_VARIANCE',
                    'signals' => $signalValues,
                ];
            }
            $entries = array_map(fn (int $bike): array => $entriesByBike[$bike], $sourceBikes);
            $spool->append(['year' => $year, 'race_id' => $raceId, 'entries' => $entries]);
        }
    }

    private function path(string $directory, string $role): string
    {
        return rtrim($directory, '/').'/bt03e07-'.$role.'-'.bin2hex(random_bytes(8)).'.jsonl';
    }
}
