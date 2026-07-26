<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceCalculationDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\StatFeatureValueDto;
use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Domain\Keirin\Statistics\Enums\StatDataQualityStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureValueType;
use App\Domain\Keirin\Statistics\Enums\StatInputSnapshotType;
use InvalidArgumentException;
use JsonException;

final class Stat01Calculator
{
    public const STAT_CODE = 'STAT-01';

    public const CALCULATION_VERSION = 'STAT-01-v1';

    /**
     * @return list<array{feature_code:string,feature_name:string,value_type:string,unit_code:string,description:string}>
     */
    public static function featureDefinitions(): array
    {
        return [
            ['feature_code' => 'RACE_SCORE_RAW', 'feature_name' => 'Race score', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Validated race-card race score.'],
            ['feature_code' => 'RACE_SCORE_AVAILABLE', 'feature_name' => 'Race score availability', 'value_type' => 'BOOLEAN', 'unit_code' => 'NONE', 'description' => 'Whether a validated eligible race score is available.'],
            ['feature_code' => 'RACE_SCORE_RANK', 'feature_name' => 'Race score competition rank', 'value_type' => 'INTEGER', 'unit_code' => 'RANK', 'description' => 'Descending standard competition rank within the race.'],
            ['feature_code' => 'RACE_SCORE_DENSE_RANK', 'feature_name' => 'Race score dense rank', 'value_type' => 'INTEGER', 'unit_code' => 'RANK', 'description' => 'Descending dense rank within the race.'],
            ['feature_code' => 'RACE_SCORE_RANK_PERCENTILE', 'feature_name' => 'Race score rank percentile', 'value_type' => 'NUMERIC', 'unit_code' => 'PERCENTILE', 'description' => 'Rank percentile where the highest score is 1.0.'],
            ['feature_code' => 'RACE_SCORE_MEAN', 'feature_name' => 'Race score mean', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Mean of valid race scores in the race.'],
            ['feature_code' => 'RACE_SCORE_MAX', 'feature_name' => 'Race score maximum', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Maximum valid race score in the race.'],
            ['feature_code' => 'RACE_SCORE_DIFF_FROM_MEAN', 'feature_name' => 'Race score difference from mean', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Subject score minus race mean.'],
            ['feature_code' => 'RACE_SCORE_GAP_TO_MAX', 'feature_name' => 'Race score gap to maximum', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Race maximum minus subject score.'],
            ['feature_code' => 'RACE_SCORE_STDDEV_POP', 'feature_name' => 'Race score population standard deviation', 'value_type' => 'NUMERIC', 'unit_code' => 'SCORE', 'description' => 'Population standard deviation of valid race scores.'],
            ['feature_code' => 'RACE_SCORE_Z', 'feature_name' => 'Race score z-score', 'value_type' => 'NUMERIC', 'unit_code' => 'NONE', 'description' => 'Subject z-score; absent when population standard deviation is zero.'],
        ];
    }

    /**
     * STAT-01-v1 uses population standard deviation because the complete valid
     * entrant set is the population being compared within a single race.
     *
     * @throws JsonException
     */
    public function calculate(Stat01RaceInputDto $race): Stat01RaceCalculationDto
    {
        $entries = $this->validatedEntries($race);
        $states = [];
        $validScores = [];
        $missingCount = 0;
        $invalidCount = 0;

        foreach ($entries as $entry) {
            $state = match (true) {
                ! $entry->raceScoreEligible => 'leakage',
                $entry->validationStatus === RaceScoreValidationStatus::Missing => 'missing',
                $entry->validationStatus === RaceScoreValidationStatus::Valid
                    && $entry->raceScore !== null
                    && is_finite($entry->raceScore)
                    && $entry->raceScore > 0.0 => 'valid',
                default => 'invalid',
            };
            $states[$entry->raceEntryId] = $state;
            if ($state === 'valid') {
                $validScores[] = $entry->raceScore;
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
        $inputHash = $this->inputHash($race, $entries);

        $results = [];
        foreach ($entries as $entry) {
            $state = $states[$entry->raceEntryId];
            [$status, $quality] = $this->status($race, $entry, $state, $validCount, $entrantCount);
            $features = [
                $this->booleanFeature(
                    'RACE_SCORE_AVAILABLE',
                    $state === 'valid',
                    'NONE',
                    $status,
                ),
            ];
            if ($state === 'valid' && $race->inputAsOf !== null && $average !== null && $maximum !== null && $standardDeviation !== null) {
                $score = $entry->raceScore;
                $rank = 1 + count(array_filter($validScores, static fn (float $candidate): bool => $candidate > $score));
                $denseRank = 1 + count(array_unique(array_filter(
                    $validScores,
                    static fn (float $candidate): bool => $candidate > $score,
                ), SORT_REGULAR));
                // STAT-01-v1 maps standard competition rank to [0, 1].
                $percentile = $validCount === 1 ? 1.0 : ($validCount - $rank) / ($validCount - 1);
                $features = [
                    $this->numericFeature('RACE_SCORE_RAW', $score, 'SCORE', $status),
                    $features[0],
                    $this->integerFeature('RACE_SCORE_RANK', $rank, 'RANK', $status),
                    $this->integerFeature('RACE_SCORE_DENSE_RANK', $denseRank, 'RANK', $status),
                    $this->numericFeature('RACE_SCORE_RANK_PERCENTILE', $percentile, 'PERCENTILE', $status, $validCount - $rank, max(1, $validCount - 1)),
                    $this->numericFeature('RACE_SCORE_MEAN', $average, 'SCORE', $status, array_sum($validScores), $validCount),
                    $this->numericFeature('RACE_SCORE_MAX', $maximum, 'SCORE', $status),
                    $this->numericFeature('RACE_SCORE_DIFF_FROM_MEAN', $score - $average, 'SCORE', $status),
                    $this->numericFeature('RACE_SCORE_GAP_TO_MAX', $maximum - $score, 'SCORE', $status),
                    $this->numericFeature('RACE_SCORE_STDDEV_POP', $standardDeviation, 'SCORE', $status, sampleCount: $validCount),
                ];
                if ($standardDeviation > 0.0) {
                    $features[] = $this->numericFeature(
                        'RACE_SCORE_Z',
                        ($score - $average) / $standardDeviation,
                        'NONE',
                        $status,
                    );
                }
            }

            $results[] = new Stat01EntryResultDto(
                raceEntryId: $entry->raceEntryId,
                raceEntrySnapshotId: $entry->raceEntrySnapshotId,
                playerId: $entry->playerId,
                bikeNumber: $entry->bikeNumber,
                inputSnapshotType: $entry->inputSnapshotType,
                status: $status,
                dataQualityStatus: $quality,
                validScoreCount: $validCount,
                missingScoreCount: $missingCount,
                invalidScoreCount: $invalidCount,
                entrantCount: $entrantCount,
                sourceFetchedAt: $entry->fetchedAt,
                features: $features,
            );
        }

        return new Stat01RaceCalculationDto($race->raceId, $inputHash, $results);
    }

    /**
     * @param  list<Stat01EntryInputDto>  $entries
     *
     * @throws JsonException
     */
    private function inputHash(Stat01RaceInputDto $race, array $entries): string
    {
        return hash('sha256', json_encode([
            'stat_code' => self::STAT_CODE,
            'calculation_version' => self::CALCULATION_VERSION,
            'race_id' => $race->raceId,
            'input_as_of' => $race->inputAsOf?->format(DATE_ATOM),
            'input_as_of_policy' => $race->inputAsOfPolicy->value,
            'entry_snapshot_hashes' => array_map(
                static fn (Stat01EntryInputDto $entry): array => [
                    'race_entry_id' => $entry->raceEntryId,
                    'snapshot_hash' => $entry->snapshotHash,
                ],
                $entries,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{StatFeatureStatus,StatDataQualityStatus}
     */
    private function status(
        Stat01RaceInputDto $race,
        Stat01EntryInputDto $entry,
        string $state,
        int $validCount,
        int $entrantCount,
    ): array {
        if ($entry->inputSnapshotType === StatInputSnapshotType::CurrentPlayerProfile->value) {
            return [StatFeatureStatus::LeakageRisk, StatDataQualityStatus::LeakageRisk];
        }
        if ($race->inputAsOf === null) {
            return [StatFeatureStatus::Blocked, StatDataQualityStatus::Blocked];
        }

        return match ($state) {
            'leakage' => [StatFeatureStatus::LeakageRisk, StatDataQualityStatus::LeakageRisk],
            'missing' => [
                $validCount === 0 ? StatFeatureStatus::Blocked : StatFeatureStatus::MissingInput,
                $validCount === 0 ? StatDataQualityStatus::Blocked : StatDataQualityStatus::Partial,
            ],
            'invalid' => [
                StatFeatureStatus::InvalidInput,
                $validCount === 0 ? StatDataQualityStatus::Blocked : StatDataQualityStatus::Partial,
            ],
            default => match (true) {
                $validCount < $entrantCount => [StatFeatureStatus::Degraded, StatDataQualityStatus::Partial],
                $entry->playerId === null || $entry->sourceLinkMissing => [StatFeatureStatus::Degraded, StatDataQualityStatus::Degraded],
                default => [StatFeatureStatus::Valid, StatDataQualityStatus::Valid],
            },
        };
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
            if ($entry->raceEntryId < 1 || $entry->bikeNumber < 1 || $entry->bikeNumber > 9) {
                throw new InvalidArgumentException('STAT-01 race entry identity was invalid.');
            }
            if (isset($entryIds[$entry->raceEntryId]) || isset($bikeNumbers[$entry->bikeNumber])) {
                throw new InvalidArgumentException('STAT-01 entries contained duplicate IDs or bike numbers.');
            }
            $entryIds[$entry->raceEntryId] = true;
            $bikeNumbers[$entry->bikeNumber] = true;
        }

        return array_values($entries);
    }

    private function integerFeature(string $code, int $value, string $unit, StatFeatureStatus $status): StatFeatureValueDto
    {
        return new StatFeatureValueDto($code, StatFeatureValueType::Integer, $unit, $status, $value);
    }

    private function booleanFeature(string $code, bool $value, string $unit, StatFeatureStatus $status): StatFeatureValueDto
    {
        return new StatFeatureValueDto($code, StatFeatureValueType::Boolean, $unit, $status, $value);
    }

    private function numericFeature(
        string $code,
        float $value,
        string $unit,
        StatFeatureStatus $status,
        ?float $numerator = null,
        ?float $denominator = null,
        ?int $sampleCount = null,
    ): StatFeatureValueDto {
        if (! is_finite($value) || ($numerator !== null && ! is_finite($numerator)) || ($denominator !== null && ! is_finite($denominator))) {
            throw new InvalidArgumentException("STAT-01 feature {$code} was not finite.");
        }

        return new StatFeatureValueDto(
            $code,
            StatFeatureValueType::Numeric,
            $unit,
            $status,
            $value,
            $numerator,
            $denominator,
            $sampleCount,
        );
    }
}
