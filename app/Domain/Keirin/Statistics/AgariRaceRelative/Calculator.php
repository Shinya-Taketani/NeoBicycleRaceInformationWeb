<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\TrackContext\AgariSpeedCalculator;
use App\Domain\Keirin\TrackContext\StructureValues;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

final class Calculator
{
    public function __construct(private readonly RaceCategoryPolicy $categories, private readonly AgariSpeedCalculator $speed) {}

    public function calculate(array $race, TrackContextMaster $master): array
    {
        $rows = $race['results'];
        usort($rows, fn ($a, $b) => [$a['bike_number'], $a['id']] <=> [$b['bike_number'], $b['id']]);
        $times = [];
        $normal = 0;
        foreach ($rows as $entry) {
            $time = $entry['agari_time_seconds'] === null ? null : StructureValues::positiveDecimal($entry['agari_time_seconds']);
            if (is_array($entry['observation']) && ($entry['observation']['agari_time_seconds'] ?? null) !== null) {
                StructureValues::positiveDecimal($entry['observation']['agari_time_seconds']);
            }
            if (in_array($entry['result_status'], ['FINISHED', 'TIED'], true)) {
                $normal++;
                if ($entry['agari_status'] === 'VALID' && $time !== null) {
                    $times[$entry['id']] = $time;
                }
            }
        }
        $n = count($times);
        $reasons = $this->reasons($race, $rows, $master);
        $resolution = $master->resolve('keirin_jp', $race['context']['track_code'] ?? '', $race['race_date']);
        $minimum = null;
        foreach ($times as $time) {
            if ($minimum === null || $time->isLessThan($minimum)) {
                $minimum = $time;
            }
        }
        $calculated = [];
        foreach ($rows as $entry) {
            $reason = match (true) {
                $reasons !== [] => 'RACE_EXCLUDED',
                ! in_array($entry['result_status'], ['FINISHED', 'TIED'], true) => 'ABNORMAL_RESULT',
                ! isset($times[$entry['id']]) => $entry['agari_status'] ?? 'MISSING_AGARI_STORAGE',
                $n < 2 => 'INSUFFICIENT_COMPARISON',
                default => null,
            };
            $relative = ['rank_min' => null, 'rank_average' => null, 'percentile' => null,
                'percentile_numerator' => null, 'percentile_denominator' => null, 'gap_to_fastest_seconds' => null];
            if ($reason === null) {
                $time = $times[$entry['id']];
                $faster = count(array_filter($times, fn ($t) => $t->isLessThan($time)));
                $equal = count(array_filter($times, fn ($t) => $t->isEqualTo($time)));
                $numerator = 2 * ($n - 1) - 2 * $faster - ($equal - 1);
                $denominator = 2 * ($n - 1);
                $relative = ['rank_min' => 1 + $faster,
                    'rank_average' => (string) BigDecimal::of(2 + 2 * $faster + $equal - 1)->dividedByExact(2)->strippedOfTrailingZeros(),
                    'percentile' => $this->ratio($numerator, $denominator),
                    'percentile_numerator' => $numerator, 'percentile_denominator' => $denominator,
                    'gap_to_fastest_seconds' => (string) $time->minus($minimum)->strippedOfTrailingZeros()];
            }
            $speed = $reasons === [] ? $this->speed->calculate($resolution, $entry['agari_time_seconds'], Contract::DEFINITION,
                AgariStatus::from($entry['agari_status']), RaceEntryResultStatus::from($entry['result_status']))
                : ['calculable' => false, 'reason' => 'RACE_EXCLUDED', 'speed_mps' => null, 'speed_kmh' => null];
            $external = $entry['observation']['external_player_id'] ?? null;
            $calculated[] = [...Contract::DISCLOSURE, 'result_id' => $entry['id'], 'bike_number' => $entry['bike_number'],
                'current_race_entry_id' => $entry['race_entry_id'] ?? null, 'current_player_id' => $entry['player_id'] ?? null,
                'observation_id' => $entry['observation']['id'] ?? null,
                'observation_race_entry_id' => $entry['observation']['race_entry_id'] ?? null,
                'observation_player_id' => $entry['observation']['player_id'] ?? null, 'external_player_id' => $external,
                'link_status' => is_string($external) && preg_match('/\A[0-9]{6}\z/', $external) ? 'OBSERVED_EXTERNAL_ID_ONLY_TIMING_UNVERIFIED' : 'UNRESOLVED_EXTERNAL_ID',
                'race_result_import_id' => $entry['race_result_import_id'], 'result_status' => $entry['result_status'],
                'agari_status' => $entry['agari_status'], 'agari_raw_text' => $entry['agari_raw_text'],
                'agari_time_seconds' => $entry['agari_time_seconds'], 'relative_status' => $reason ?? 'CALCULATED',
                ...$relative, 'speed' => $speed];
        }

        return [...Contract::DISCLOSURE, 'calculation_version' => Contract::VERSION,
            'race_id' => $race['race_id'], 'race_date' => $race['race_date'],
            'race_status' => $race['race_status'], 'exclusion_reasons' => $reasons,
            'relative_status' => $reasons !== [] ? 'EXCLUDED' : ($n < 2 ? 'INSUFFICIENT_COMPARISON' : 'CALCULATED'),
            'normal_finisher_count' => $normal, 'valid_timing_count' => $n,
            'missing_or_invalid_normal_finisher_count' => $normal - $n,
            'abnormal_result_count' => count($rows) - $normal,
            'comparison_scope' => 'OBSERVED_VALID_NORMAL_FINISHERS',
            'comparison_completeness' => $normal === $n ? 'COMPLETE' : 'PARTIAL',
            'measurement_definition_id' => Contract::DEFINITION, 'distance_status' => $resolution->status,
            'results' => $calculated];
    }

    private function ratio(int $numerator, int $denominator): string
    {
        try {
            return (string) BigDecimal::of($numerator)->dividedByExact($denominator)->strippedOfTrailingZeros();
        } catch (RoundingNecessaryException) {
            return (string) BigDecimal::of($numerator)->dividedBy($denominator, 12, RoundingMode::HalfEven);
        }
    }

    private function reasons(array $race, array $rows, TrackContextMaster $master): array
    {
        $reasons = [];
        if ($this->categories->classify($race['race_type']) !== RaceCategory::Men) {
            $reasons[] = 'UNSUPPORTED_CATEGORY';
        }
        if (! in_array($race['race_status'], ['CONFIRMED', 'CORRECTED'], true)) {
            $reasons[] = $race['race_status'] === 'CANCELLED' ? 'CANCELLED' : 'RESULT_NOT_FINAL';
        }
        if ($rows === []) {
            $reasons[] = 'NO_CURRENT_RESULTS';
        }
        $importIds = array_unique(array_column($rows, 'race_result_import_id'), SORT_REGULAR);
        if (count($importIds) > 1) {
            $reasons[] = 'MIXED_IMPORT_VERSIONS';
        }
        $bikes = array_column($rows, 'bike_number');
        if (count(array_unique($bikes)) !== count($rows) || count(array_unique(array_column($rows, 'id'))) !== count($rows)
            || array_diff($bikes, range(1, 9)) !== []) {
            $reasons[] = 'INVALID_OR_DUPLICATE_RESULT_IDENTITY';
        }
        $definition = $master->definitions[Contract::DEFINITION] ?? [];
        if (($definition['status'] ?? null) !== 'CONFIRMED' || ($definition['kind'] ?? null) !== 'HALF_LAP') {
            $reasons[] = 'UNKNOWN_MEASUREMENT_DEFINITION';
        }
        foreach ($rows as $entry) {
            $import = $entry['import'];
            $observation = $entry['observation'];
            if (! is_array($import) || ($import['id'] ?? null) !== $entry['race_result_import_id']
                || ($import['race_id'] ?? null) !== $race['race_id'] || ($import['import_status'] ?? null) !== 'SUCCEEDED') {
                $reasons[] = 'INVALID_IMPORT_REFERENCE';

                continue;
            }
            if (($import['result_count'] ?? null) !== count($rows)) {
                $reasons[] = 'IMPORT_RESULT_COUNT_MISMATCH';
            }
            if (! in_array($import['requested_result_status'] ?? null, ['CONFIRMED', 'CORRECTED'], true)
                || ($import['parsed_page_status'] ?? null) !== 'RESULTS_AVAILABLE') {
                $reasons[] = 'IMPORT_RESULT_NOT_FINAL';
            }
            if (! is_array($observation)) {
                $reasons[] = 'MISSING_OBSERVATION';

                continue;
            }
            foreach (['race_id' => $race['race_id'], 'race_result_import_id' => $entry['race_result_import_id'],
                'bike_number' => $entry['bike_number'], 'result_status' => $entry['result_status'],
                'agari_raw_text' => $entry['agari_raw_text'], 'agari_status' => $entry['agari_status']] as $key => $value) {
                if (! array_key_exists($key, $observation) || $observation[$key] !== $value) {
                    $reasons[] = 'OBSERVATION_IDENTITY_OR_VALUE_MISMATCH';
                }
            }
            $time = $entry['agari_time_seconds'];
            $observedTime = $observation['agari_time_seconds'] ?? null;
            if (($time === null) !== ($observedTime === null)
                || ($time !== null && $observedTime !== null && ! BigDecimal::of($time)->isEqualTo($observedTime))) {
                $reasons[] = 'OBSERVATION_IDENTITY_OR_VALUE_MISMATCH';
            }
            $metadata = $observation['metadata'] ?? [];
            foreach (['source_hash', 'converted_hash'] as $key) {
                if (! is_string($import[$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $import[$key])
                    || ($metadata[$key] ?? null) !== $import[$key]) {
                    $reasons[] = 'SOURCE_HASH_MISMATCH';
                }
            }
            if (($observation['source_url'] ?? null) !== ($import['source_url'] ?? null)
                || ! in_array(parse_url($import['source_url'] ?? '', PHP_URL_HOST), ['keirin.jp', 'www.keirin.jp'], true)
                || ! in_array(parse_url($import['source_url'] ?? '', PHP_URL_SCHEME), ['https', 'http'], true)
                || ($metadata['semantic'] ?? null) !== 'AGARI_TIME'
                || ($observation['parser_version'] ?? null) !== 'AGARI-STORAGE-v1'
                || ($metadata['normalizer_version'] ?? null) !== 'AGARI-TIME-v1'
                || ! is_string($import['parser_version'] ?? null) || $import['parser_version'] === ''
                || ($metadata['source_parser_version'] ?? null) !== $import['parser_version']) {
                $reasons[] = 'UNCONFIRMED_MEASUREMENT_MAPPING';
            }
            $agari = AgariStatus::tryFrom($entry['agari_status'] ?? '');
            $status = RaceEntryResultStatus::tryFrom($entry['result_status'] ?? '');
            if ($agari === null || $status === null
                || (in_array($agari, [AgariStatus::Valid, AgariStatus::ObservedAbnormalResult], true) !== ($time !== null))
                || ($agari === AgariStatus::Valid && ! in_array($status, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true))
                || ($agari === AgariStatus::ObservedAbnormalResult && in_array($status, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true))) {
                $reasons[] = 'INVALID_RESULT_AGARI_STATE';
            }
        }
        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return $reasons;
    }
}
