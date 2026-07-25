<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceCalculationDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use InvalidArgumentException;
use JsonException;

final class Stat01Calculator
{
    public const STAT_CODE = 'STAT-01';

    public const CALCULATION_VERSION = 'STAT-01-v1';

    /**
     * STAT-01-v1 uses population standard deviation because the complete valid
     * entrant set is the population being compared within a single race.
     *
     * @throws JsonException
     */
    public function calculate(Stat01RaceInputDto $race): Stat01RaceCalculationDto
    {
        $entries = $this->validatedEntries($race);
        $classified = [];
        $validScores = [];
        $missingCount = 0;
        $invalidCount = 0;

        foreach ($entries as $entry) {
            $score = $this->validScore($entry->raceScore);
            $state = match (true) {
                $entry->raceScore === null => 'missing',
                $score === null => 'invalid',
                default => 'valid',
            };
            $classified[$entry->raceEntryId] = ['state' => $state, 'score' => $score];
            if ($state === 'valid') {
                $validScores[] = $score;
            } elseif ($state === 'missing') {
                $missingCount++;
            } else {
                $invalidCount++;
            }
        }

        $validCount = count($validScores);
        $entrantCount = count($entries);
        $average = $validCount > 0 ? array_sum($validScores) / $validCount : null;
        $maximum = $validCount > 0 ? max($validScores) : null;
        $standardDeviation = $average !== null
            ? sqrt(array_sum(array_map(
                static fn (float $score): float => ($score - $average) ** 2,
                $validScores,
            )) / $validCount)
            : null;

        $snapshot = $this->snapshot($race, $entries);
        $inputHash = hash('sha256', json_encode(
            [
                'stat_code' => self::STAT_CODE,
                'calculation_version' => self::CALCULATION_VERSION,
                'input' => $snapshot,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        $results = [];
        foreach ($entries as $entry) {
            $state = $classified[$entry->raceEntryId]['state'];
            $score = $classified[$entry->raceEntryId]['score'];
            if ($state !== 'valid') {
                $results[] = new Stat01EntryResultDto(
                    raceEntryId: $entry->raceEntryId,
                    playerId: $entry->playerId,
                    bikeNumber: $entry->bikeNumber,
                    raceScore: null,
                    validScoreCount: $validCount,
                    missingScoreCount: $missingCount,
                    invalidScoreCount: $invalidCount,
                    entrantCount: $entrantCount,
                    scoreRank: null,
                    denseRank: null,
                    strengthPercentile: null,
                    raceAverageScore: $average,
                    raceMaxScore: $maximum,
                    differenceFromAverage: null,
                    differenceFromMax: null,
                    raceStandardDeviation: $standardDeviation,
                    zScore: null,
                    qualityStatus: $state === 'invalid'
                        ? StatisticQualityStatus::InvalidInput
                        : ($validCount === 0 ? StatisticQualityStatus::Blocked : StatisticQualityStatus::MissingInput),
                    acquisitionMode: $entry->acquisitionMode,
                    sourceFetchedAt: $entry->fetchedAt,
                );

                continue;
            }

            $rank = 1 + count(array_filter($validScores, static fn (float $candidate): bool => $candidate > $score));
            $denseRank = 1 + count(array_unique(array_filter(
                $validScores,
                static fn (float $candidate): bool => $candidate > $score,
            ), SORT_REGULAR));
            // Version v1 maps standard competition rank to [0, 1].
            $percentile = $validCount === 1 ? 1.0 : ($validCount - $rank) / ($validCount - 1);
            $quality = $validCount < $entrantCount
                ? StatisticQualityStatus::Partial
                : ($entry->acquisitionMode === StatisticAcquisitionMode::HistoricalRaceCard
                    ? StatisticQualityStatus::HistoricalSnapshot
                    : StatisticQualityStatus::Valid);

            $results[] = new Stat01EntryResultDto(
                raceEntryId: $entry->raceEntryId,
                playerId: $entry->playerId,
                bikeNumber: $entry->bikeNumber,
                raceScore: $score,
                validScoreCount: $validCount,
                missingScoreCount: $missingCount,
                invalidScoreCount: $invalidCount,
                entrantCount: $entrantCount,
                scoreRank: $rank,
                denseRank: $denseRank,
                strengthPercentile: $percentile,
                raceAverageScore: $average,
                raceMaxScore: $maximum,
                differenceFromAverage: $score - $average,
                differenceFromMax: $score - $maximum,
                raceStandardDeviation: $standardDeviation,
                zScore: $standardDeviation > 0.0 ? ($score - $average) / $standardDeviation : null,
                qualityStatus: $quality,
                acquisitionMode: $entry->acquisitionMode,
                sourceFetchedAt: $entry->fetchedAt,
            );
        }

        return new Stat01RaceCalculationDto(
            raceId: $race->raceId,
            inputHash: $inputHash,
            inputSnapshot: $snapshot,
            results: $results,
        );
    }

    /**
     * @return list<Stat01EntryInputDto>
     */
    private function validatedEntries(Stat01RaceInputDto $race): array
    {
        if ($race->raceId < 1 || $race->entries === []) {
            throw new InvalidArgumentException('STAT-01 requires a persisted race with at least one entry.');
        }

        $entries = $race->entries;
        usort($entries, static fn (Stat01EntryInputDto $left, Stat01EntryInputDto $right): int => [
            $left->raceEntryId,
            $left->bikeNumber,
        ] <=> [
            $right->raceEntryId,
            $right->bikeNumber,
        ]);
        $entryIds = [];
        $bikeNumbers = [];
        foreach ($entries as $entry) {
            if ($entry->raceEntryId < 1) {
                throw new InvalidArgumentException('STAT-01 race_entry ID was invalid.');
            }
            if ($entry->bikeNumber < 1 || $entry->bikeNumber > 9) {
                throw new InvalidArgumentException('STAT-01 bike number was outside 1 through 9.');
            }
            if (isset($entryIds[$entry->raceEntryId]) || isset($bikeNumbers[$entry->bikeNumber])) {
                throw new InvalidArgumentException('STAT-01 entries contained duplicate IDs or bike numbers.');
            }
            $entryIds[$entry->raceEntryId] = true;
            $bikeNumbers[$entry->bikeNumber] = true;
        }

        return array_values($entries);
    }

    private function validScore(?string $score): ?float
    {
        if ($score === null || preg_match('/^\d+(?:\.\d+)?$/', $score) !== 1) {
            return null;
        }

        $number = (float) $score;

        return is_finite($number) && $number > 0.0 ? $number : null;
    }

    /**
     * @param  list<Stat01EntryInputDto>  $entries
     * @return array<string,mixed>
     */
    private function snapshot(Stat01RaceInputDto $race, array $entries): array
    {
        return [
            'race' => [
                'race_id' => $race->raceId,
                'source' => $race->source,
                'race_date' => $race->raceDate,
                'scheduled_start_at' => $race->scheduledStartAt?->format(DATE_ATOM),
            ],
            'entries' => array_map(static fn (Stat01EntryInputDto $entry): array => [
                'race_entry_id' => $entry->raceEntryId,
                'player_id' => $entry->playerId,
                'bike_number' => $entry->bikeNumber,
                'race_score' => $entry->raceScore,
                'fetched_at' => $entry->fetchedAt?->format(DATE_ATOM),
                'acquisition_mode' => $entry->acquisitionMode->value,
            ], $entries),
        ];
    }
}
