<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Stat17DistributionInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat17DistributionResultDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus as Status;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use InvalidArgumentException;

class Stat17DistributionCalculator
{
    public const STAT_CODE = 'STAT-17';

    public const CALCULATION_VERSION = 'STAT17-PJ0315-DISPLAYED-COUNT-PROFILE-v1';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function calculate(Stat17DistributionInputDto $input): Stat17DistributionResultDto
    {
        $row = $input->observation;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['race_date'] ?? '');
        if ($date === false || $date->format('Y-m-d') !== $row['race_date'] || $row['race_date'] < '2022-01-01'
            || $row['race_date'] > '2025-12-31' || ($row['use_class'] ?? null) !== 'RETROSPECTIVE_ONLY') {
            throw new InvalidArgumentException('Invalid/unsupported STAT-17 snapshot.');
        }
        $counts = $missing = [];
        foreach (['escape_count', 'sprint_count', 'overtake_count', 'mark_count'] as $name) {
            if (! array_key_exists($name, $row) || ($row[$name] !== null && (! is_int($row[$name]) || $row[$name] < 0 || $row[$name] > 9999))) {
                throw new InvalidArgumentException('Invalid STAT-17 displayed count.');
            }
            $counts[$name] = $row[$name];
            if ($row[$name] === null) {
                $missing[] = $name;
            }
        }
        $total = $missing === [] ? array_sum($counts) : null;
        $composition = $total !== null && $total > 0 ? array_map(fn ($n) => $n / $total, $counts) : null;
        $fetched = $row['fetched_at'] ?? null;
        $saved = $row['fetch_log_saved_at'] ?? null;
        $features = ['profile' => 'PJ0315_DISPLAYED_COUNTS_ONLY_NOT_USAGE_OR_SUCCESS_RATES',
            'RETROSPECTIVE' => ['counts' => $counts, 'total_displayed_count' => $total, 'composition' => $composition,
                'observed_tactic_types' => $missing === [] ? count(array_filter($counts, fn ($n) => $n > 0)) : null,
                'distribution_status' => $missing !== [] ? 'MISSING_COUNTS' : ($total === 0 ? 'ZERO_TOTAL_UNDEFINED' : 'COMPUTABLE'),
                'missing_fields' => $missing, 'use' => 'RETROSPECTIVE_ONLY'],
            'points' => null, 'confidence' => null, 'prediction_use' => 'NOT_AUTHORIZED'];
        $evidence = ['observation_id' => $row['id'], 'race_entry_id' => $row['race_entry_id'], 'external_player_id' => $row['external_player_id'],
            'fetch_log_id' => $row['scraping_fetch_log_id'], 'raw_sha256' => $row['raw_sha256'],
            'source_metadata_hash' => $row['source_metadata_hash'], 'observation_version' => $row['parser_version'],
            'raw_fields' => $row['raw_fields'], 'fetched_at' => $fetched, 'fetch_log_saved_at' => $saved,
            'capture_before_input_as_of' => $fetched !== null && $saved !== null
                && new DateTimeImmutable($fetched) <= new DateTimeImmutable($saved)
                && new DateTimeImmutable($fetched) < $input->inputAsOf && new DateTimeImmutable($saved) < $input->inputAsOf,
            'aggregation_period_status' => 'UNKNOWN', 'aggregation_basis_status' => 'UNKNOWN', 'official_publication_at' => null,
            'official_publication_status' => 'UNKNOWN',
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'reason' => 'AGGREGATION_WINDOW_BASELINE_AND_TARGET_RESULT_EXCLUSION_NOT_ESTABLISHED'];

        return new Stat17DistributionResultDto($missing === [] ? Status::Partial : Status::MissingInput, StatisticQualityStatus::Degraded,
            $features, $evidence, $this->hasher->hash(['version' => self::CALCULATION_VERSION, 'observation' => $row,
                'input_as_of' => $input->inputAsOf->format(DATE_ATOM)]));
    }
}
