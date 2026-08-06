<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat01EntryFeatureDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceFeatureDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Enums\InputAsOfSource;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;

class Stat01Calculator
{
    public const STAT_CODE = 'STAT-01';

    public const CALCULATION_VERSION = 'STAT-01-existing-db-v1';

    private const SUBJECT_TYPE = 'RACE_ENTRY';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function calculate(Stat01RaceInputDto $race): Stat01RaceFeatureDto
    {
        $entries = $race->entries;
        usort($entries, fn (Stat01EntryInputDto $left, Stat01EntryInputDto $right): int => $left->id <=> $right->id);

        $validEntries = array_values(array_filter(
            $entries,
            fn (Stat01EntryInputDto $entry): bool => $entry->raceScore !== null && (float) $entry->raceScore > 0,
        ));
        $validCount = count($validEntries);
        $missingCount = count(array_filter($entries, fn (Stat01EntryInputDto $entry): bool => $entry->raceScore === null));
        $invalidCount = count($entries) - $validCount - $missingCount;
        $entryCount = count($entries);
        $entryCountMatches = $entryCount === $race->entrantCount;
        $scores = array_map(fn (Stat01EntryInputDto $entry): float => (float) $entry->raceScore, $validEntries);
        $mean = $validCount > 0 ? array_sum($scores) / $validCount : null;
        $maximum = $validCount > 0 ? max($scores) : null;
        $variance = $validCount > 0
            ? array_sum(array_map(fn (float $score): float => ($score - $mean) ** 2, $scores)) / $validCount
            : null;
        $standardDeviation = $variance !== null ? sqrt($variance) : null;
        [$inputAsOf, $inputAsOfSource] = $this->inputAsOf($race);
        $raceInputHash = $this->raceInputHash($race, $entries, $inputAsOf);
        $scoreCoverageRate = $race->entrantCount > 0 ? $validCount / $race->entrantCount : null;
        $raceHasIncompleteScores = $validCount !== $entryCount;
        $results = [];

        foreach ($entries as $entry) {
            $score = $entry->raceScore !== null ? (float) $entry->raceScore : null;
            $available = $score !== null && $score > 0;
            $statusReason = null;
            if ($entry->raceScore === null) {
                $status = StatisticFeatureResultStatus::MissingInput;
                $statusReason = 'RACE_SCORE_MISSING';
            } elseif (! $available) {
                $status = StatisticFeatureResultStatus::InvalidInput;
                $statusReason = 'RACE_SCORE_NON_POSITIVE_UNRESOLVED';
            } elseif (! $entryCountMatches
                || $raceHasIncompleteScores
                || $entry->playerId === null
                || $inputAsOf === null) {
                $status = StatisticFeatureResultStatus::Partial;
            } else {
                $status = StatisticFeatureResultStatus::Valid;
            }

            $qualityReasons = [];
            if (! $entryCountMatches) {
                $qualityReasons[] = 'ENTRY_COUNT_MISMATCH';
            }
            if ($entry->playerId === null) {
                $qualityReasons[] = 'PLAYER_ID_UNRESOLVED';
            }
            if ($inputAsOf === null) {
                $qualityReasons[] = 'INPUT_AS_OF_MISSING';
            }
            if ($statusReason !== null) {
                $qualityReasons[] = $statusReason;
            }
            if ($available && $standardDeviation === 0.0) {
                $qualityReasons[] = 'ZERO_VARIANCE';
            }

            $qualityStatus = match (true) {
                $entry->playerId === null || $inputAsOf === null => StatisticQualityStatus::Degraded,
                ! $entryCountMatches || $raceHasIncompleteScores => StatisticQualityStatus::Partial,
                default => StatisticQualityStatus::Full,
            };

            $rank = $available
                ? 1 + count(array_filter($scores, fn (float $other): bool => $other > $score))
                : null;
            $distinctHigherScores = $available
                ? array_unique(array_map(
                    fn (float $other): string => number_format($other, 2, '.', ''),
                    array_filter($scores, fn (float $other): bool => $other > $score),
                ))
                : [];
            $denseRank = $available ? 1 + count($distinctHigherScores) : null;
            $percentile = $available
                ? ($validCount === 1 ? 1.0 : ($validCount - $rank) / ($validCount - 1))
                : null;
            $zScore = $available && $standardDeviation !== null && $standardDeviation > 0
                ? ($score - $mean) / $standardDeviation
                : null;

            $features = [
                'RACE_SCORE_RAW' => $score,
                'RACE_SCORE_AVAILABLE' => $available,
                'RACE_SCORE_RANK' => $rank,
                'RACE_SCORE_DENSE_RANK' => $denseRank,
                'RACE_SCORE_STRENGTH_PERCENTILE' => $percentile,
                'RACE_SCORE_RACE_MEAN' => $available ? $mean : null,
                'RACE_SCORE_RACE_MAX' => $available ? $maximum : null,
                'RACE_SCORE_DIFF_FROM_MEAN' => $available ? $score - $mean : null,
                'RACE_SCORE_GAP_TO_MAX' => $available ? $maximum - $score : null,
                'RACE_SCORE_STDDEV_POP' => $available ? $standardDeviation : null,
                'RACE_SCORE_Z' => $zScore,
                'VALID_SCORE_COUNT' => $validCount,
                'MISSING_SCORE_COUNT' => $missingCount,
                'INVALID_SCORE_COUNT' => $invalidCount,
                'ENTRANT_COUNT' => $race->entrantCount,
            ];
            $sourceFetchedAfterStart = $entry->fetchedAt !== null && $race->scheduledStartAt !== null
                ? $entry->fetchedAt > $race->scheduledStartAt
                : null;
            $evidence = [
                'entry_count' => $entryCount,
                'expected_entrant_count' => $race->entrantCount,
                'entry_count_matches' => $entryCountMatches,
                'valid_score_count' => $validCount,
                'missing_score_count' => $missingCount,
                'invalid_score_count' => $invalidCount,
                'score_coverage_rate' => $scoreCoverageRate,
                'player_id_resolved' => $entry->playerId !== null,
                'input_as_of_source' => $inputAsOfSource->value,
                'acquisition_mode' => StatisticAcquisitionMode::Backfill->value,
                'source_fetched_at' => $this->timestamp($entry->fetchedAt),
                'source_fetched_after_start' => $sourceFetchedAfterStart,
                'calculation_version' => self::CALCULATION_VERSION,
                'race_input_hash' => $raceInputHash,
                'status_reason' => $statusReason,
                'quality_reasons' => $qualityReasons,
            ];
            $inputHash = $this->hasher->hash([
                'stat_code' => self::STAT_CODE,
                'calculation_version' => self::CALCULATION_VERSION,
                'race_input_hash' => $raceInputHash,
                'subject_type' => self::SUBJECT_TYPE,
                'race_entry_id' => $entry->id,
            ]);

            $results[] = new Stat01EntryFeatureDto(
                entry: $entry,
                status: $status,
                qualityStatus: $qualityStatus,
                inputAsOf: $inputAsOf,
                features: $features,
                evidence: $evidence,
                inputHash: $inputHash,
            );
        }

        $partial = ! $entryCountMatches;
        foreach ($results as $result) {
            if ($result->status !== StatisticFeatureResultStatus::Valid
                || $result->qualityStatus !== StatisticQualityStatus::Full) {
                $partial = true;
                break;
            }
        }

        return new Stat01RaceFeatureDto(
            raceId: $race->id,
            entries: $results,
            partial: $partial,
        );
    }

    /**
     * @param  list<Stat01EntryInputDto>  $entries  Entries sorted by race_entry_id ascending.
     */
    private function raceInputHash(
        Stat01RaceInputDto $race,
        array $entries,
        ?DateTimeImmutable $inputAsOf,
    ): string {
        return $this->hasher->hash([
            'version' => [
                'stat_code' => self::STAT_CODE,
                'calculation_version' => self::CALCULATION_VERSION,
            ],
            'race' => [
                'race_id' => $race->id,
                'race_date' => $race->raceDate->format('Y-m-d'),
                'race_type' => $race->raceType,
                'entrant_count' => $race->entrantCount,
                'sales_close_at' => $this->timestamp($race->salesCloseAt),
                'scheduled_start_at' => $this->timestamp($race->scheduledStartAt),
                'input_as_of' => $this->timestamp($inputAsOf),
            ],
            'entries' => array_map(fn (Stat01EntryInputDto $entry): array => [
                'race_entry_id' => $entry->id,
                'player_id' => $entry->playerId,
                'bike_number' => $entry->bikeNumber,
                'grade' => $entry->grade,
                'race_score' => $entry->raceScore,
                'fetched_at' => $this->timestamp($entry->fetchedAt),
            ], $entries),
            'acquisition' => [
                'acquisition_mode' => StatisticAcquisitionMode::Backfill->value,
            ],
        ]);
    }

    /** @return array{0:?DateTimeImmutable,1:InputAsOfSource} */
    private function inputAsOf(Stat01RaceInputDto $race): array
    {
        if ($race->salesCloseAt !== null) {
            return [$race->salesCloseAt, InputAsOfSource::SalesCloseAt];
        }
        if ($race->scheduledStartAt !== null) {
            return [$race->scheduledStartAt, InputAsOfSource::ScheduledStartAtFallback];
        }

        return [null, InputAsOfSource::Missing];
    }

    private function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d\TH:i:s.uP');
    }
}
