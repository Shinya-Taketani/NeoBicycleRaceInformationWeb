<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt02LabelDefinition;
use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use DateTimeImmutable;
use RuntimeException;

final class Bt03e07TrainingDatasetBuilder
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly FeatureEligibilityEvaluator $baselineEligibility,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly Bt02LabelDefinition $labels,
        private readonly EffectBinBuilder $binBuilder,
        private readonly Bt03e07ReadOnlyQueryAudit $queryAudit,
    ) {}

    public function buildRaw(int $year, Bt02OutcomeContextSnapshot $snapshot, string $temporaryDirectory): Bt03e02RaceSpool
    {
        $this->assertYear($year);
        $this->queryAudit->recordSnapshotYear($year);
        $spool = new Bt03e02RaceSpool('RAW', $this->path($temporaryDirectory, "raw-{$year}"));
        $fold = new FoldDefinitionDto("BT03E02_{$year}", 0, null, null, new DateTimeImmutable("{$year}-01-01"), new DateTimeImmutable("{$year}-12-31"));
        try {
            foreach ($snapshot->chunks($fold, self::CHUNK_SIZE) as $chunk) {
                $raceIds = array_map(static fn (Bt02OutcomeContextRaceDto $race): int => $race->context->raceId, $chunk);
                $this->queryAudit->recordFeatureSourceYear($year);
                $baselineByRace = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $raceIds);
                $signals = [];
                foreach (Bt03e07Contract::STAT_CODES as $statCode) {
                    $this->queryAudit->recordFeatureSourceYear($year);
                    $signals[$statCode] = $this->signalFeatures->forRaces(
                        $this->signalManifest->for($year, $statCode)->featureRunId,
                        $statCode,
                        $raceIds,
                    );
                }
                foreach ($chunk as $snapshotRace) {
                    $race = $this->rawRace($year, $snapshotRace, $baselineByRace, $signals);
                    if ($race !== null) {
                        $spool->append($race);
                    }
                }
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
        $spool = new Bt03e02RaceSpool('BINNED', $this->path($temporaryDirectory, 'binned-'.$role));
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

    /**
     * @param  array<int, list<object>>  $baselineByRace
     * @param  array<string, array<int, list<object>>>  $signals
     * @return array{year:int,race_id:int,entries:list<array<string,mixed>>}|null
     */
    private function rawRace(int $year, Bt02OutcomeContextRaceDto $snapshotRace, array $baselineByRace, array $signals): ?array
    {
        $context = $snapshotRace->context;
        if (! in_array($context->resultStatus, ['CONFIRMED', 'CORRECTED'], true)) {
            return null;
        }
        $baseline = $baselineByRace[$context->raceId] ?? [];
        if (! $this->baselineEligibility->evaluate($context, $baseline)->eligible) {
            return null;
        }
        $results = [];
        foreach ($snapshotRace->results as $result) {
            if (isset($results[$result->bikeNumber])) {
                throw new RuntimeException('BT-03E-07 outcome snapshot duplicated a bike number.');
            }
            $results[$result->bikeNumber] = $result;
        }
        $baselineBikes = array_map(static fn (object $feature): int => $feature->bikeNumber, $baseline);
        $resultBikes = array_map('intval', array_keys($results));
        sort($baselineBikes, SORT_NUMERIC);
        sort($resultBikes, SORT_NUMERIC);
        if (count($results) !== $context->entrantCount || $baselineBikes !== $resultBikes) {
            throw new RuntimeException('BT-03E-07 outcome entrants did not match STAT-01 entrants.');
        }
        $signalMaps = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
            foreach ($signals[$statCode][$context->raceId] ?? [] as $signal) {
                if (isset($signalMaps[$statCode][$signal->raceEntryId])) {
                    throw new RuntimeException("BT-03E-07 {$statCode} duplicated a race entry.");
                }
                $signalMaps[$statCode][$signal->raceEntryId] = $signal;
            }
        }
        $raws = array_map(static fn (object $feature): float => (float) $feature->raceScoreRaw, $baseline);
        $mean = array_sum($raws) / count($raws);
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $raws)) / count($raws);
        $deviation = sqrt($variance);
        $entries = [];
        foreach ($baseline as $feature) {
            $signalValues = [];
            foreach (Bt03e07Contract::STAT_CODES as $statCode) {
                $signal = $signalMaps[$statCode][$feature->raceEntryId] ?? null;
                $signalValues[] = $signal !== null && $this->signalEligibility->eligible($statCode, Bt02SignalCohort::Operational, $signal)
                    ? $signal->primaryValue
                    : null;
            }
            $result = $results[$feature->bikeNumber];
            $binary = $this->labels->labels($result->resultStatus, $result->rank);
            $entries[] = [
                'id' => $feature->raceEntryId,
                'bike' => $feature->bikeNumber,
                'raw' => (float) $feature->raceScoreRaw,
                'stat01_rank' => $feature->raceScoreRank,
                'anchor' => $deviation > 0.0 ? ((float) $feature->raceScoreRaw - $mean) / $deviation : 0.0,
                'anchor_status' => $deviation > 0.0 ? 'AVAILABLE' : 'ZERO_VARIANCE',
                'signals' => $signalValues,
                'labels' => [$binary->isWin, $binary->isTop2, $binary->isTop3],
                'rank' => $result->rank,
                'status' => $result->resultStatus,
            ];
        }

        return ['year' => $year, 'race_id' => $context->raceId, 'entries' => $entries];
    }

    private function assertYear(int $year): void
    {
        if (! in_array($year, Bt03e07Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException('BT-03E-07 dataset year was outside 2022-2025.');
        }
    }

    private function path(string $directory, string $role): string
    {
        return rtrim($directory, '/').'/bt03e07-'.$role.'-'.bin2hex(random_bytes(8)).'.jsonl';
    }
}
