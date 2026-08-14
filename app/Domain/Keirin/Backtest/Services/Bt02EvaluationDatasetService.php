<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt02LabelDefinition;
use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use DateTimeImmutable;
use RuntimeException;

class Bt02EvaluationDatasetService implements Bt02EvaluationDataset
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly Bt02OutcomeContextSnapshotSession $snapshotSession,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly FeatureEligibilityEvaluator $baselineEligibility,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly Bt02LabelDefinition $labelDefinition,
    ) {}

    public function rows(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
    {
        if ($from > $to || (int) $from->format('Y') < 2022 || (int) $to->format('Y') > 2025) {
            throw new RuntimeException('BT-02 dataset range was outside the fixed 2022-2025 contract.');
        }

        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            $yearStart = new DateTimeImmutable("{$year}-01-01");
            $yearEnd = new DateTimeImmutable("{$year}-12-31");
            $yearFrom = $from > $yearStart ? $from : $yearStart;
            $yearTo = $to < $yearEnd ? $to : $yearEnd;
            $baselineSource = $this->baselineManifest->forYear($year);
            $signalSource = $this->signalManifest->for($year, $statCode);
            $fold = new FoldDefinitionDto('BT02_DATASET', 0, null, null, $yearFrom, $yearTo);

            foreach ($this->snapshotSession->snapshot()->chunks($fold, self::CHUNK_SIZE) as $snapshotRaces) {
                $raceIds = array_map(fn (Bt02OutcomeContextRaceDto $race): int => $race->context->raceId, $snapshotRaces);
                $baselineByRace = $this->baselineFeatures->forRaces($baselineSource->featureRunId, $raceIds);
                $signalByRace = $this->signalFeatures->forRaces($signalSource->featureRunId, $statCode, $raceIds);

                foreach ($snapshotRaces as $snapshotRace) {
                    $race = $snapshotRace->context;
                    if (! in_array($race->resultStatus, ['CONFIRMED', 'CORRECTED'], true)) {
                        continue;
                    }
                    $baseline = $baselineByRace[$race->raceId] ?? [];
                    if (! $this->baselineEligibility->evaluate($race, $baseline)->eligible) {
                        continue;
                    }
                    $signal = $signalByRace[$race->raceId] ?? [];
                    $matched = $this->signalEligibility->matchedSet(
                        $statCode,
                        $cohort,
                        array_map(fn ($feature): int => $feature->raceEntryId, $baseline),
                        $signal,
                    );
                    $baselineMap = $this->byEntry($baseline);
                    $signalMap = $this->byEntry($signal);
                    $labelMap = $this->byBike($snapshotRace->results);
                    $baselineBikes = array_map(fn ($feature): int => $feature->bikeNumber, $baseline);
                    $labelBikes = array_map('intval', array_keys($labelMap));
                    sort($baselineBikes, SORT_NUMERIC);
                    sort($labelBikes, SORT_NUMERIC);
                    if (count($labelMap) !== $race->entrantCount || $labelBikes !== $baselineBikes) {
                        throw new RuntimeException('BT-02 confirmed race results did not match the official entrant set.');
                    }

                    foreach ($matched->raceEntryIds as $raceEntryId) {
                        $baselineFeature = $baselineMap[$raceEntryId] ?? throw new RuntimeException('Matched baseline feature was missing.');
                        $signalFeature = $signalMap[$raceEntryId] ?? throw new RuntimeException('Matched signal feature was missing.');
                        $label = $labelMap[$baselineFeature->bikeNumber] ?? throw new RuntimeException('Matched BT-02 result label was missing.');
                        yield new Bt02EvaluationRowDto(
                            raceId: $race->raceId,
                            raceEntryId: $raceEntryId,
                            baselineValue: (float) $baselineFeature->raceScoreRaw,
                            signalValue: $signalFeature->primaryValue ?? throw new RuntimeException('Matched BT-02 signal value was missing.'),
                            labels: $this->labelDefinition->labels($label->resultStatus, $label->rank),
                        );
                    }
                }
            }
        }
    }

    /** @param list<object> $rows @return array<int, object> */
    private function byEntry(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (isset($map[$row->raceEntryId])) {
                throw new RuntimeException('BT-02 entry feature was duplicated.');
            }
            $map[$row->raceEntryId] = $row;
        }

        return $map;
    }

    /** @param list<object> $rows @return array<int, object> */
    private function byBike(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (isset($map[$row->bikeNumber])) {
                throw new RuntimeException('BT-02 result bike number was duplicated.');
            }
            $map[$row->bikeNumber] = $row;
        }

        return $map;
    }
}
