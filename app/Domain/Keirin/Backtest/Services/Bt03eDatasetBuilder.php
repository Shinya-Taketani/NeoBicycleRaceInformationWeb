<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03FixedBinAssigner;
use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Support\Bt03eRaceSpool;
use DateTimeImmutable;
use RuntimeException;

class Bt03eDatasetBuilder
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly FeatureEligibilityEvaluator $baselineEligibility,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly Bt03FixedBinAssigner $binAssigner,
    ) {}

    /**
     * @param  list<Bt03eBinRuleDto>  $rules
     * @return array{spool: Bt03eRaceSpool, snapshot_races: int, excluded_races: int, excluded_reasons: array<string, int>}
     */
    public function build(int $year, array $rules, Bt02OutcomeContextSnapshot $snapshot, string $temporaryDirectory): array
    {
        if (! in_array($year, [Bt03eContract::TRAINING_YEAR, Bt03eContract::EVALUATION_YEAR], true)) {
            throw new RuntimeException('BT-03E dataset year was outside 2023-2024.');
        }
        $ruleIndex = $this->ruleIndex($rules);
        $path = rtrim($temporaryDirectory, '/').'/bt03e-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03eRaceSpool($year, $path);
        $snapshotRaces = $excludedRaces = 0;
        $excludedReasons = [];
        $fold = new FoldDefinitionDto(
            "BT03E_{$year}", 0, null, null,
            new DateTimeImmutable("{$year}-01-01"),
            new DateTimeImmutable("{$year}-12-31"),
        );

        try {
            foreach ($snapshot->chunks($fold, self::CHUNK_SIZE) as $snapshotChunk) {
                $snapshotRaces += count($snapshotChunk);
                $raceIds = array_map(static fn (Bt02OutcomeContextRaceDto $race): int => $race->context->raceId, $snapshotChunk);
                $baselineByRace = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $raceIds);
                $signals = [];
                foreach (Bt03eContract::STAT_CODES as $statCode) {
                    $signals[$statCode] = $this->signalFeatures->forRaces(
                        $this->signalManifest->for($year, $statCode)->featureRunId,
                        $statCode,
                        $raceIds,
                    );
                }

                foreach ($snapshotChunk as $snapshotRace) {
                    $reason = $this->appendRace($spool, $snapshotRace, $baselineByRace, $signals, $ruleIndex);
                    if ($reason !== null) {
                        $excludedRaces++;
                        $excludedReasons[$reason] = ($excludedReasons[$reason] ?? 0) + 1;
                    }
                }
            }
            $spool->seal();

            return [
                'spool' => $spool,
                'snapshot_races' => $snapshotRaces,
                'excluded_races' => $excludedRaces,
                'excluded_reasons' => $excludedReasons,
            ];
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /**
     * @param  array<int, list<object>>  $baselineByRace
     * @param  array<string, array<int, list<object>>>  $signals
     * @param  array<string, array{bins: list<object>, directions: array<int, int>}>  $ruleIndex
     */
    private function appendRace(Bt03eRaceSpool $spool, Bt02OutcomeContextRaceDto $snapshotRace, array $baselineByRace, array $signals, array $ruleIndex): ?string
    {
        $race = $snapshotRace->context;
        if (! in_array($race->resultStatus, ['CONFIRMED', 'CORRECTED'], true)) {
            return 'RESULT_NOT_FINAL';
        }
        $baseline = $baselineByRace[$race->raceId] ?? [];
        if (! $this->baselineEligibility->evaluate($race, $baseline)->eligible) {
            return 'STAT01_BASELINE_INELIGIBLE';
        }
        $resultByBike = [];
        foreach ($snapshotRace->results as $result) {
            if (isset($resultByBike[$result->bikeNumber])) {
                throw new RuntimeException('BT-03E outcome snapshot contained a duplicate bike number.');
            }
            $resultByBike[$result->bikeNumber] = $result;
        }
        $baselineBikes = array_map(static fn (object $feature): int => $feature->bikeNumber, $baseline);
        $resultBikes = array_map('intval', array_keys($resultByBike));
        sort($baselineBikes, SORT_NUMERIC);
        sort($resultBikes, SORT_NUMERIC);
        if (count($resultByBike) !== $race->entrantCount || $baselineBikes !== $resultBikes) {
            throw new RuntimeException('BT-03E fixed outcome entrants did not match STAT-01 entrants.');
        }

        $signalMaps = [];
        foreach (Bt03eContract::STAT_CODES as $statCode) {
            foreach ($signals[$statCode][$race->raceId] ?? [] as $feature) {
                if (isset($signalMaps[$statCode][$feature->raceEntryId])) {
                    throw new RuntimeException("BT-03E {$statCode} duplicated a race entry.");
                }
                $signalMaps[$statCode][$feature->raceEntryId] = $feature;
            }
        }

        $entries = [];
        foreach ($baseline as $feature) {
            $directions = [];
            foreach (Bt03eContract::STAT_CODES as $statCode) {
                $signal = $signalMaps[$statCode][$feature->raceEntryId] ?? null;
                if ($signal === null || ! $this->signalEligibility->eligible($statCode, Bt02SignalCohort::Operational, $signal)) {
                    $directions[] = 0;

                    continue;
                }
                $assignment = $this->binAssigner->assign($ruleIndex[$statCode]['bins'], $signal->primaryValue);
                $directions[] = $assignment === null || $assignment->binOrigin !== 'TRAINING_BIN'
                    ? 0
                    : ($ruleIndex[$statCode]['directions'][$assignment->binIndex] ?? 0);
            }
            $result = $resultByBike[$feature->bikeNumber];
            $entries[] = [
                'id' => $feature->raceEntryId,
                'bike' => $feature->bikeNumber,
                'raw' => (float) $feature->raceScoreRaw,
                'directions' => $directions,
                'rank' => $result->rank,
                'status' => $result->resultStatus,
            ];
        }
        $spool->append($race->raceId, $entries);

        return null;
    }

    /** @param list<Bt03eBinRuleDto> $rules @return array<string, array{bins: list<object>, directions: array<int, int>}> */
    private function ruleIndex(array $rules): array
    {
        $index = [];
        foreach ($rules as $rule) {
            if (! $rule instanceof Bt03eBinRuleDto || isset($index[$rule->statCode]['directions'][$rule->binIndex])) {
                throw new RuntimeException('BT-03E rule set was invalid or duplicated.');
            }
            $index[$rule->statCode]['bins'][] = $rule->sourceBin();
            $index[$rule->statCode]['directions'][$rule->binIndex] = $rule->directionStrength;
        }
        if (array_keys($index) !== Bt03eContract::STAT_CODES) {
            throw new RuntimeException('BT-03E rule set did not contain exactly 12 stats.');
        }

        return $index;
    }
}
